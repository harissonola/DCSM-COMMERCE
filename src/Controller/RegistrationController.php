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
        if ($form->isSubmitted()) {
            try {
                if (!$form->isValid()) {
                    throw new \Exception("Le formulaire contient des erreurs. Veuillez vérifier les champs.");
                }

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
                    $mailer,
                    $githubUploader
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
        MailerInterface $mailer,
        GitHubUploader $githubUploader
    ): void {
        $referralCode = uniqid('ref_', true);
        $user->setReferralCode($referralCode);

        $this->handleReferralSystem($user, $form, $entityManager);

        $user->setPassword($passwordHasher->hashPassword($user, $form->get('plainPassword')->getData()))
            ->setCreatedAt(new \DateTimeImmutable())
            ->setMiningBotActive(0)
            ->setBalance(0);

        $this->handleProfileImageUpload($user, $form, $githubUploader);

        $entityManager->persist($user);
        $entityManager->flush();

        $this->generateAndSaveQrCode($user, $urlGenerator, $githubUploader, $entityManager);

        $this->sendConfirmationEmail($user);
        $this->sendReferralEmail(
            $user,
            $urlGenerator->generate('app_register', ['ref' => $referralCode], UrlGeneratorInterface::ABSOLUTE_URL),
            $mailer
        );
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

    private function handleProfileImageUpload(User $user, $form, GitHubUploader $uploader): void
    {
        try {
            $image = $form->get('photo')->getData();
            if ($image instanceof UploadedFile) {
                $fileContent = file_get_contents($image->getPathname());
                $filePath = sprintf('uploads/profile/%s.%s', uniqid('', true), $image->guessExtension());
                $cdnUrl = $uploader->uploadFile($fileContent, $filePath, 'Upload photo profil');
                $user->setPhoto($cdnUrl);
                return;
            }
        } catch (\Exception $e) {
            $this->addFlash('warning', 'Erreur lors de l\'upload de l\'image : ' . $e->getMessage());
        }
        $user->setPhoto($this->getDefaultProfileImage());
    }

    private function getDefaultProfileImage(): string
    {
        return sprintf('https://cdn.jsdelivr.net/gh/harissonola/my-cdn@main/uploads/profile/default%d.jpg', rand(1, 7));
    }

    private function generateAndSaveQrCode(
        User $user,
        UrlGeneratorInterface $urlGenerator,
        GitHubUploader $uploader,
        EntityManagerInterface $entityManager
    ): void {
        try {
            $referralLink = $urlGenerator->generate(
                'app_register',
                ['ref' => $user->getReferralCode()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            $qrCode = new QrCode($referralLink);
            $writer = new PngWriter();
            $qrResult = $writer->write($qrCode);

            $filePath = sprintf('uploads/qrcodes/%s.png', $user->getReferralCode());
            $cdnUrl = $uploader->uploadFile($qrResult->getString(), $filePath, 'Upload QR Code');
            $user->setQrCodePath($cdnUrl);
            $entityManager->flush();
        } catch (\Exception $e) {
            $this->addFlash('warning', 'Échec de génération du QR Code : ' . $e->getMessage());
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
                ->subject('Confirmer votre adresse mail')
                ->htmlTemplate('registration/confirmation_email.html.twig')
        );
    }

    private function sendReferralEmail(User $user, string $referralLink, MailerInterface $mailer): void
    {
        try {
            $email = (new TemplatedEmail())
                ->from(new Address('no-reply@dcsm-commerce.com', 'DCSM COMMERCE'))
                ->to($user->getEmail())
                ->subject('Votre lien d\'affiliation et QR Code')
                ->htmlTemplate('emails/referral_email.html.twig')
                ->context([
                    'user' => $user,
                    'referralLink' => $referralLink,
                    'qrCodePath' => $user->getQrCodePath(),
                ]);
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('warning', 'Échec de l\'envoi de l\'email de parrainage.');
        }
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