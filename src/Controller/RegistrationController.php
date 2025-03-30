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

        $referredBy = $request->query->get('ref');
        if ($referredBy) {
            $form->get('referredBy')->setData($referredBy);
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $confirmPassword = $form->get('confirmPassword')->getData();

            if ($plainPassword !== $confirmPassword) {
                $form->addError(new FormError('Les mots de passe ne correspondent pas.'));
            } else {
                $referralCode = uniqid('ref_');
                $user->setReferralCode($referralCode);

                $referredBy = $form->get('referredBy')->getData();
                if ($referredBy) {
                    $referrer = $entityManager->getRepository(User::class)->findOneBy(['referralCode' => $referredBy]);
                    if ($referrer) {
                        $user->setReferredBy($referredBy);
                        $referrer->setReferralCount($referrer->getReferralCount() + 1);
                        $this->updateReferrerRewards($referrer, $entityManager);
                    }
                }

                $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword))
                    ->setCreatedAt(new \DateTimeImmutable())
                    ->setMiningBotActive(0)
                    ->setBalance(0);

                // Upload de l'image de profil vers GitHub
                $image = $form->get('photo')->getData();
                if ($image) {
                    try {
                        $fileContent = file_get_contents($image->getPathname());
                        $filePath = 'uploads/profile/' . uniqid() . '.' . $image->guessExtension();
                        $cdnUrl = $githubUploader->uploadFile($fileContent, $filePath, 'Upload photo profil');
                        $user->setPhoto($cdnUrl);
                    } catch (\Exception $e) {
                        $this->addFlash('error', 'Erreur lors de l\'upload de l\'image: '.$e->getMessage());
                        return $this->redirectToRoute('app_register');
                    }
                } else {
                    $user->setPhoto("https://cdn.jsdelivr.net/gh/harissonola/my-cdn@main/uploads/profile/default" . rand(1, 7) . ".jpg");
                }

                $entityManager->persist($user);
                $entityManager->flush();

                $referralLink = $urlGenerator->generate(
                    'app_register',
                    ['ref' => $referralCode],
                    UrlGeneratorInterface::ABSOLUTE_URL
                );

                // Génération et upload du QR Code
                $qrCode = new QrCode($referralLink);
                $writer = new PngWriter();
                $qrResult = $writer->write($qrCode);

                try {
                    $qrCodeFileName = $referralCode . '.png';
                    $filePath = 'uploads/qrcodes/' . $qrCodeFileName;
                    $cdnUrl = $githubUploader->uploadFile($qrResult->getString(), $filePath, 'Upload QR Code');
                    $user->setQrCodePath($cdnUrl);
                    $entityManager->flush();
                } catch (\Exception $e) {
                    $this->addFlash('warning', 'Le QR Code n\'a pas pu être sauvegardé sur le CDN');
                }

                $this->emailVerifier->sendEmailConfirmation(
                    'app_verify_email',
                    $user,
                    (new TemplatedEmail())
                        ->from(new Address('no-reply@dcsm-commerce.com', 'DCSM COMMERCE'))
                        ->to((string) $user->getEmail())
                        ->subject('Confirmer votre adresse mail')
                        ->htmlTemplate('registration/confirmation_email.html.twig')
                );

                $this->sendReferralEmail($user, $referralLink, $user->getQrCodePath(), $mailer);

                return $security->login($user, AppAuthenticator::class, 'main');
            }
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
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
            return $this->redirectToRoute('app_dashboard');
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('error', 'L\'adresse email n\'est pas valide.');
            return $this->redirectToRoute('app_dashboard');
        }
    }

    #[Route('/verify/email/resend', name: 'app_verify_email_send')]
    public function resendEmailConfirmation(MailerInterface $mailer): Response
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($user->isVerified()) {
            $this->addFlash('info', 'Votre email est déjà confirmé.');
            return $this->redirectToRoute('app_dashboard');
        }

        $this->emailVerifier->sendEmailConfirmation(
            'app_verify_email',
            $user,
            (new TemplatedEmail())
                ->from(new Address('no-reply@dcsm-commerce.com', 'DCSM COMMERCE'))
                ->to((string) $user->getEmail())
                ->subject('Confirmer votre adresse mail')
                ->htmlTemplate('registration/confirmation_email.html.twig')
        );

        $this->addFlash('success', 'Un nouveau lien de confirmation a été envoyé à votre adresse email.');
        return $this->redirectToRoute('app_dashboard');
    }

    private function sendReferralEmail(User $user, string $referralLink, ?string $qrCodePath, MailerInterface $mailer): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@dcsm-commerce.com', 'DCSM COMMERCE'))
            ->to($user->getEmail())
            ->subject('Votre lien d\'affiliation et QR Code')
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
            $referrer->setReferralRewardRate(13.0);
            $referrer->setBalance($referrer->getBalance() + 10);
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
}