<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Security\AppAuthenticator;
use App\Security\EmailVerifier;
use App\Service\GitHubUploader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\Mailer\MailerInterface;

class RegistrationController extends AbstractController
{
    private EmailVerifier $emailVerifier;

    public function __construct(EmailVerifier $emailVerifier)
    {
        $this->emailVerifier = $emailVerifier;
    }

    #[Route('/register', name: 'app_register')]
    public function register(
        Request $request,
        UserPasswordHasherInterface $userPasswordHasher,
        Security $security,
        EntityManagerInterface $entityManager,
        UrlGeneratorInterface $urlGenerator,
        MailerInterface $mailer,
        GitHubUploader $githubUploader
    ): Response {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);

        if ($referredBy = $request->query->get('ref')) {
            $form->get('referredBy')->setData($referredBy);
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $confirmPassword = $form->get('confirmPassword')->getData();

            if ($plainPassword !== $confirmPassword) {
                $form->addError(new FormError('Les mots de passe ne correspondent pas.'));
                return $this->render('registration/register.html.twig', [
                    'registrationForm' => $form->createView(),
                ]);
            }

            try {
                // Création de l'utilisateur
                $referralCode = uniqid('ref_');
                $user->setReferralCode($referralCode)
                    ->setPassword($userPasswordHasher->hashPassword($user, $plainPassword))
                    ->setCreatedAt(new \DateTimeImmutable())
                    ->setMiningBotActive(0)
                    ->setBalance(0);

                // Gestion du parrainage
                if ($referredBy = $form->get('referredBy')->getData()) {
                    if ($referrer = $entityManager->getRepository(User::class)->findOneBy(['referralCode' => $referredBy])) {
                        $user->setReferredBy($referredBy);
                        $referrer->setReferralCount($referrer->getReferralCount() + 1);
                        $this->updateReferrerRewards($referrer, $entityManager);
                    }
                }

                // Upload de la photo de profil
                if ($image = $form->get('photo')->getData()) {
                    $filePath = 'uploads/profile/' . uniqid() . '.' . $image->guessExtension();
                    $cdnUrl = $githubUploader->uploadFile(
                        file_get_contents($image->getPathname()),
                        $filePath,
                        'Upload photo profil'
                    );
                    $user->setPhoto($cdnUrl);
                } else {
                    $defaultImage = rand(1, 7);
                    $user->setPhoto("https://cdn.jsdelivr.net/gh/harissonola/my-cdn@main/uploads/profile/default{$defaultImage}.jpg");
                }

                $entityManager->persist($user);
                $entityManager->flush();

                // Génération du QR Code
                $referralLink = $urlGenerator->generate(
                    'app_register',
                    ['ref' => $referralCode],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                $qrCode = new QrCode($referralLink);
                $writer = new PngWriter();
                $qrResult = $writer->write($qrCode);

                $qrCodePath = 'uploads/qrcodes/' . $referralCode . '.png';
                $cdnUrl = $githubUploader->uploadFile(
                    $qrResult->getString(),
                    $qrCodePath,
                    'QR Code parrainage'
                );
                $user->setQrCodePath($cdnUrl);
                $entityManager->flush();

                // Envoi des emails
                $this->sendConfirmationEmail($user);
                $this->sendReferralEmail($user, $referralLink, $cdnUrl, $mailer);

                return $security->login($user, AppAuthenticator::class, 'main');
            } catch (\Exception $e) {
                $this->addFlash('error', 'Une erreur est survenue: ' . $e->getMessage());
                return $this->redirectToRoute('app_register');
            }
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }

    private function sendConfirmationEmail(User $user): void
    {
        $this->emailVerifier->sendEmailConfirmation(
            'app_verify_email',
            $user,
            (new TemplatedEmail())
                ->from(new Address('no-reply@dcsm-commerce.com', 'DCSM COMMERCE'))
                ->to($user->getEmail())
                ->subject('Confirmez votre email')
                ->htmlTemplate('registration/confirmation_email.html.twig')
        );
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyEmail(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_dashboard');
        }

        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
            $this->addFlash('success', 'Votre adresse email a été confirmée avec succès.');
        } catch (VerifyEmailExceptionInterface $e) {
            $this->addFlash('error', 'L\'adresse email n\'est pas valide.');
        }

        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/verify/email/resend', name: 'app_verify_email_send')]
    public function resendEmailConfirmation(): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($user->isVerified()) {
            $this->addFlash('info', 'Votre email est déjà confirmé.');
        } else {
            $this->sendConfirmationEmail($user);
            $this->addFlash('success', 'Un nouveau lien de confirmation a été envoyé.');
        }

        return $this->redirectToRoute('app_dashboard');
    }

    private function sendReferralEmail(User $user, string $referralLink, ?string $qrCodePath, MailerInterface $mailer): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@dcsm-commerce.com', 'DCSM COMMERCE'))
            ->to($user->getEmail())
            ->subject('Votre lien d\'affiliation')
            ->htmlTemplate('emails/referral_email.html.twig')
            ->context([
                'user' => $user,
                'referralLink' => $referralLink,
                'qrCodePath' => $qrCodePath,
            ]);
        $mailer->send($email);
    }

    private function updateReferrerRewards(User $referrer, EntityManagerInterface $entityManager): void
    {
        $count = $referrer->getReferralCount();

        if ($count >= 40) {
            $referrer->setReferralRewardRate(13.0)->setBalance($referrer->getBalance() + 10);
        } elseif ($count >= 20) {
            $referrer->setReferralRewardRate(10.0);
        } elseif ($count >= 10) {
            $referrer->setReferralRewardRate(7.0);
        } elseif ($count >= 5) {
            $referrer->setReferralRewardRate(6.0);
        }

        $entityManager->flush();
    }
}