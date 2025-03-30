<?php

namespace App\Controller;

use App\Entity\User;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Authentication\UserAuthenticatorInterface;
use App\Security\AppAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Github\Client;

class GoogleController extends AbstractController
{
    private Client $githubClient;

    public function __construct(Client $githubClient)
    {
        $this->githubClient = $githubClient;
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

        $email = $userData->getEmail();
        $googleId = $userData->getId();
        $fname = $userData->getFirstName() ?? $userData->getName();
        $lname = $userData->getLastName() ?? '';
        $username = strtolower(substr($lname, 0, 1) . $fname);
        if (empty(trim($username))) $username = strtolower($email);

        $existingUser = $entityManager->getRepository(User::class)
            ->findOneBy(['googleId' => $googleId])
            ?? $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

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

        // Generate QR Code
        $referralLink = $urlGenerator->generate(
            'app_register',
            ['ref' => $referralCode],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $qrCode = new QrCode($referralLink);
        $writer = new PngWriter();
        $qrResult = $writer->write($qrCode);

        // Upload to GitHub
        $qrCodeFileName = $referralCode . '.png';
        $filePath = "uploads/qrcodes/$qrCodeFileName";
        try {
            $cdnUrl = $this->uploadToGitHub($filePath, $qrResult->getString(), 'QR Code via Google Login');
            $user->setQrCodePath($cdnUrl);
        } catch (\Exception $e) {
            $this->addFlash('error', "QR Upload Failed: " . $e->getMessage());
        }

        $entityManager->persist($user);
        $entityManager->flush();

        $this->sendReferralEmail($user, $referralLink, $user->getQrCodePath(), $mailer);

        return $authenticator->authenticateUser($user, $appAuthenticator, $request);
    }

    private function uploadToGitHub(string $filePath, string $content, string $message): string
    {
        $repoOwner = 'harissonola';
        $repoName = 'my-cdn';
        $branch = 'main';

        $this->githubClient->authenticate('YOUR_GITHUB_TOKEN', null, Client::AUTH_ACCESS_TOKEN);

        $contentsApi = $this->githubClient->api('repo')->contents();
        $response = $contentsApi->create(
            $repoOwner,
            $repoName,
            $filePath,
            base64_encode($content),
            $message,
            $branch
        );

        return $response['content']['download_url'];
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