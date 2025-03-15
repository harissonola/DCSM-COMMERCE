<?php

namespace App\Controller;

use App\Service\FtpService;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use App\Security\AppAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use App\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GoogleController extends AbstractController
{
    private $ftpService;

    public function __construct(FtpService $ftpService)
    {
        $this->ftpService = $ftpService;
    }

    #[Route('/connect/google', name: 'connect_google_start')]
    public function connectGoogle(ClientRegistry $clientRegistry): Response
    {
        return $clientRegistry->getClient('google')->redirect(['profile', 'email', 'address']);
    }

    #[Route('/connect/google/check', name: 'connect_google_check')]
    public function connectGoogleCheck(
        Request $request,
        ClientRegistry $clientRegistry,
        EntityManagerInterface $entityManager,
        UserAuthenticatorInterface $authenticator,
        AppAuthenticator $appAuthenticator,
        UserPasswordHasherInterface $passwordHasher,
        MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        $client = $clientRegistry->getClient('google');
        $userData = $client->fetchUser();

        $email    = $userData->getEmail();
        $googleId = $userData->getId();

        $fname = $userData->getFirstName() ?? $userData->getName();
        $lname = $userData->getLastName() ?? '';
        $firstLetter = substr($lname, 0, 1);
        $username = strtolower($firstLetter . $fname);
        if (empty(trim($username))) {
            $username = strtolower($email);
        }

        $existingUser = $entityManager->getRepository(User::class)->findOneBy(['googleId' => $googleId]);
        if (!$existingUser) {
            $existingUser = $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        }

        if ($existingUser) {
            if (!$existingUser->getGoogleId()) {
                $existingUser->setGoogleId($googleId);
                $entityManager->flush();
            }
            return $authenticator->authenticateUser($existingUser, $appAuthenticator, $request);
        }

        $user = new User();
        $user->setGoogleId($googleId)
             ->setEmail($email)
             ->setUsername($username)
             ->setFname($fname)
             ->setLname($lname)
             ->setPassword($passwordHasher->hashPassword($user, uniqid()))
             ->setPhoto($userData->getAvatar())
             ->setCountry('BJ')
             ->setCreatedAt(new \DateTimeImmutable())
             ->setMiningBotActive(false)
             ->setVerified(true);

        $referralCode = uniqid('ref_');
        $user->setReferralCode($referralCode);
        $entityManager->persist($user);
        $entityManager->flush();

        // Générer le lien d'affiliation et le QR Code
        $referralLink = $urlGenerator->generate('app_register', ['ref' => $referralCode], UrlGeneratorInterface::ABSOLUTE_URL);
        $qrCode = new QrCode($referralLink);
        $writer = new PngWriter();
        $qrResult = $writer->write($qrCode);

        // Stockage local dans le dossier public (par exemple "public/uploads/user/")
        $uploadDir = $this->getParameter('qr_code_directory');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $qrCodeFileName = $referralCode . '.png';
        $filePath = $uploadDir . $qrCodeFileName;
        file_put_contents($filePath, $qrResult->getString());

        // Construire l'URL publique absolue
        $publicQrCodeUrl = $this->generateUrl('homepage', [], UrlGeneratorInterface::ABSOLUTE_URL) . 'uploads/user/' . $qrCodeFileName;
        $user->setQrCodePath($publicQrCodeUrl);
        $entityManager->flush();

        $this->sendReferralEmail($user, $referralLink, $publicQrCodeUrl, $mailer);

        return $authenticator->authenticateUser($user, $appAuthenticator, $request);
    }

    private function sendReferralEmail(User $user, string $referralLink, string $qrCodePath, MailerInterface $mailer): void
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
}