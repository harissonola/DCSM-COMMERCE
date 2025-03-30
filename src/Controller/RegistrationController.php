<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Security\AppAuthenticator;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Github\Client;

class RegistrationController extends AbstractController
{
    private EmailVerifier $emailVerifier;
    private Client $githubClient;

    public function __construct(EmailVerifier $emailVerifier, Client $githubClient)
    {
        $this->emailVerifier = $emailVerifier;
        $this->githubClient = $githubClient;
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        Security $security,
        EntityManagerInterface $entityManager,
        UrlGeneratorInterface $urlGenerator,
        MailerInterface $mailer
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);

        // Gestion du parrainage
        $referredBy = $request->query->get('ref');
        if ($referredBy) {
            $form->get('referredBy')->setData($referredBy);
        }

        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $plainPassword = $form->get('plainPassword')->getData();
                $confirmPassword = $form->get('confirmPassword')->getData();
                if ($plainPassword !== $confirmPassword) {
                    throw new \Exception("Les mots de passe ne correspondent pas.");
                }

                $this->processRegistration(
                    $user,
                    $form,
                    $userPasswordHasher,
                    $entityManager,
                    $urlGenerator,
                    $mailer
                );

                return $security->login($user, AppAuthenticator::class, 'main');
            } catch (\Exception $e) {
                $this->addFlash('error', $e->getMessage());
                $entityManager->clear();
            }
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }

    private function processRegistration(
        User $user,
        $form,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        UrlGeneratorInterface $urlGenerator,
        MailerInterface $mailer
    ): void {
        // Génération d'un referralCode sans point
        $referralCode = uniqid('ref_', false);
        $user->setReferralCode($referralCode);

        // Configuration de l'utilisateur
        $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()))
            ->setCreatedAt(new \DateTimeImmutable())
            ->setMiningBotActive(0)
            ->setBalance(0);

        // Upload de l'image de profil
        $this->handleProfileImageUpload($user, $form);

        // Gestion du parrainage
        $this->handleReferralSystem($user, $form, $entityManager);

        // Sauvegarde en base
        $entityManager->persist($user);
        $entityManager->flush();

        // Génération et upload du QR Code
        $this->generateAndSaveQrCode($user, $urlGenerator, $entityManager);

        // Envoi des emails
        $this->sendConfirmationEmail($user);
        $this->sendReferralEmail($user, $urlGenerator, $mailer);
    }

    private function handleReferralSystem(User $user, $form, EntityManagerInterface $entityManager): void
    {
        $referredBy = $form->get('referredBy')->getData();
        if ($referredBy) {
            $referrer = $entityManager->getRepository(User::class)->findOneBy(['referralCode' => $referredBy]);
            if ($referrer) {
                $user->setReferredBy($referredBy);
                $referrer->setReferralCount($referrer->getReferralCount() + 1);
                $this->updateReferrerRewards($referrer, $entityManager);
                $entityManager->persist($referrer);
            }
        }
    }

    private function handleProfileImageUpload(User $user, $form): void
    {
        try {
            $image = $form->get('photo')->getData();
            if ($image instanceof UploadedFile) {
                $fileContent = file_get_contents($image->getPathname());
                $filePath = sprintf('uploads/profile/%s.%s', uniqid(), $image->guessExtension()); // Nom sans point
                $cdnUrl = $this->uploadToGitHub($filePath, $fileContent, 'Upload photo profil');
                $user->setPhoto($cdnUrl);
            } else {
                $user->setPhoto($this->getDefaultProfileImage());
            }
        } catch (\Exception $e) {
            $this->addFlash('warning', "Erreur d'upload : " . $e->getMessage());
            $user->setPhoto($this->getDefaultProfileImage());
        }
    }

    private function uploadToGitHub(string $filePath, string $content, string $message): string
    {
        $repoOwner = 'harissonola';
        $repoName = 'my-cdn';
        $branch = 'main';

        // Authentification via variable d'environnement
        $this->githubClient->authenticate($_ENV['GITHUB_TOKEN'], null, Client::AUTH_ACCESS_TOKEN);

        // Upload avec chemin complet (ex: "uploads/qrcodes/ref_123.png")
        $response = $this->githubClient->api('repo')->contents()->create(
            $repoOwner,
            $repoName,
            $filePath,
            base64_encode($content),
            $message,
            $branch
        );

        return $response['content']['download_url'] ?? '';
    }

    private function generateAndSaveQrCode(
        User $user,
        UrlGeneratorInterface $urlGenerator,
        EntityManagerInterface $entityManager
    ): void {
        try {
            $referralLink = $urlGenerator->generate(
                'app_register',
                ['ref' => $user->getReferralCode()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            // Génération du QR Code avec paramètres corrects
            $qrCode = new QrCode($referralLink);
            $writer = new PngWriter(['errorCorrectionLevel' => 'L']); // Utilisez 'L' pour le niveau de correction
            $qrResult = $writer->write($qrCode);

            // Chemin complet vers le dossier qrcodes
            $filePath = sprintf('uploads/qrcodes/%s.png', $user->getReferralCode());
            $cdnUrl = $this->uploadToGitHub($filePath, $qrResult->getString(), 'QR Code via Registration');
            $user->setQrCodePath($cdnUrl);

            $entityManager->flush();
        } catch (\Exception $e) {
            $this->addFlash('warning', "Échec de génération du QR Code : " . $e->getMessage());
        }
    }

    private function sendConfirmationEmail(User $user): void
    {
        $this->emailVerifier->sendEmailConfirmation(
            'app_verify_email',
            $user,
            (new TemplatedEmail())
                ->from(new Address('no-reply@dcsm-commerce.com', 'DCSM COMMERCE'))
                ->to((string) $user->getEmail())
                ->subject('Confirmation email')
                ->htmlTemplate('registration/confirmation_email.html.twig')
        );
    }

    private function sendReferralEmail(
        User $user,
        UrlGeneratorInterface $urlGenerator,
        MailerInterface $mailer
    ): void {
        try {
            $email = (new TemplatedEmail())
                ->from(new Address('no-reply@dcsm-commerce.com', 'DCSM COMMERCE'))
                ->to($user->getEmail())
                ->subject('Votre lien d\'affiliation et QR Code')
                ->htmlTemplate('emails/referral_email.html.twig')
                ->context([
                    'user' => $user,
                    'referralLink' => $urlGenerator->generate(
                        'app_register',
                        ['ref' => $user->getReferralCode()],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                    'qrCodePath' => $user->getQrCodePath(),
                ]);
            $mailer->send($email);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur d\'envoi : ' . $e->getMessage());
        }
    }

    private function updateReferrerRewards(User $referrer, EntityManagerInterface $entityManager): void
    {
        $count = $referrer->getReferralCount();
        if ($count >= 40) {
            $referrer->setReferralRewardRate(13.0)
                ->setBalance($referrer->getBalance() + 10);
        } elseif ($count >= 20) {
            $referrer->setReferralRewardRate(10.0);
        } elseif ($count >= 10) {
            $referrer->setReferralRewardRate(7.0);
        } elseif ($count >= 5) {
            $referrer->setReferralRewardRate(6.0);
        }
        $entityManager->persist($referrer);
        $entityManager->flush();
    }

    #[Route('/resend-verification-email', name: 'app_verify_email_send')]
    public function resendVerificationEmail(
        Request $request,
        EntityManagerInterface $entityManager,
        EmailVerifier $emailVerifier
    ): Response {
        $user = $this->getUser();
        if (!$user || $user->isVerified()) {
            return $this->redirectToRoute('app_register');
        }

        try {
            $emailVerifier->sendEmailConfirmation(
                'app_verify_email',
                $user,
                (new TemplatedEmail())
                    ->from(new Address('no-reply@dcsm-commerce.com', 'DCSM COMMERCE'))
                    ->to((string) $user->getEmail())
                    ->subject('Nouvelle confirmation')
                    ->htmlTemplate('registration/confirmation_email.html.twig')
            );
            $this->addFlash('success', 'Email renvoyé !');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_register');
    }

    private function getDefaultProfileImage(): string
    {
        return sprintf('https://cdn.jsdelivr.net/gh/harissonola/my-cdn@main/uploads/profile/default%d.jpg', rand(1, 7));
    }
}
