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
use Exception;
use Symfony\Component\Filesystem\Filesystem;

class GoogleController extends AbstractController
{
    private Client $githubClient;
    private Filesystem $filesystem;

    public function __construct(Client $githubClient, Filesystem $filesystem)
    {
        $this->githubClient = $githubClient;
        $this->filesystem = $filesystem;
    }

    #[Route('/connect/google', name: 'connect_google_start')]
    public function connectGoogle(ClientRegistry $clientRegistry): Response
    {
        return $clientRegistry->getClient('google')->redirect(['profile', 'email']);
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
        if (empty(trim($username))) {
            $username = strtolower($email);
        }

        // Vérification de l'existence
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

        // Création de l'utilisateur
        $user = new User();
        $referralCode = uniqid('ref_', false); // Sans point pour éviter les erreurs de nommage

        try {
            // Configuration de l'utilisateur
            $user->setGoogleId($googleId)
                ->setEmail($email)
                ->setUsername($username)
                ->setFname($fname)
                ->setLname($lname)
                ->setPassword($passwordHasher->hashPassword($user, bin2hex(random_bytes(16))))
                ->setPhoto($userData->getAvatar())
                ->setCountry('BJ')
                ->setCreatedAt(new \DateTimeImmutable())
                ->setMiningBotActive(false)
                ->setVerified(true)
                ->setReferralCode($referralCode);

            // Gestion du parrainage
            $this->handleReferralSystem($user, $request, $entityManager);

            // Génération et upload du QR Code
            $this->generateAndSaveQrCode($user, $urlGenerator, $entityManager);

            // Sauvegarde en base
            $entityManager->persist($user);
            $entityManager->flush();

            // Envoi de l'email
            $this->sendReferralEmail($user, $user->getReferralCode(), $user->getQrCodePath(), $mailer);

            return $authenticator->authenticateUser($user, $appAuthenticator, $request);
        } catch (Exception $e) {
            $this->addFlash('error', "Erreur lors de la création du compte : " . $e->getMessage());
            $entityManager->clear();
            return $this->redirectToRoute('app_register');
        }
    }

    private function handleReferralSystem(User $user, Request $request, EntityManagerInterface $entityManager): void
    {
        $referredBy = $request->query->get('ref');
        if ($referredBy) {
            $referrer = $entityManager->getRepository(User::class)->findOneBy(['referralCode' => $referredBy]);
            if ($referrer) {
                $user->setReferredBy($referredBy);
                $referrer->setReferralCount($referrer->getReferralCount() + 1);
                $this->updateReferrerRewards($referrer, $entityManager);
                $entityManager->persist($referrer);
                $entityManager->flush();
            }
        }
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

            // Génération du QR Code avec des paramètres valides
            $qrCode = new QrCode($referralLink);
            $writer = new PngWriter([
                'errorCorrectionLevel' => 'L', // Niveau de correction 'Low'
                'size' => 300 // Taille de l'image (300x300 pixels)
            ]);
            $qrResult = $writer->write($qrCode);

            // Génération d'un fichier temporaire pour éviter les erreurs de lecture
            $tempDir = $this->getParameter('kernel.project_dir') . '/var/tmp/';
            if (!$this->filesystem->exists($tempDir)) {
                $this->filesystem->mkdir($tempDir, 0755);
            }
            $tempFilePath = $tempDir . $user->getReferralCode() . '.png';
            $qrResult->saveToFile($tempFilePath);
            $fileContent = file_get_contents($tempFilePath);

            // Upload sur GitHub avec le chemin complet
            $filePath = "uploads/qrcodes/{$user->getReferralCode()}.png";
            $cdnUrl = $this->uploadToGitHub($filePath, $fileContent, 'QR Code via Google Login');

            // Suppression du fichier temporaire
            $this->filesystem->remove($tempFilePath);

            $user->setQrCodePath($cdnUrl);
            $entityManager->flush();
        } catch (\Exception $e) {
            $this->addFlash('warning', "Échec de génération du QR Code : " . $e->getMessage());
        }
    }

    private function uploadToGitHub(string $filePath, string $content, string $message): string
    {
        $repoOwner = 'harissonola';
        $repoName = 'my-cdn';
        $branch = 'main';

        try {
            // Authentification via la variable d'environnement
            $this->githubClient->authenticate($_ENV['GITHUB_TOKEN'], null, Client::AUTH_ACCESS_TOKEN);

            // Upload avec le chemin complet (ex: "uploads/qrcodes/ref_123456.png")
            $response = $this->githubClient->api('repo')->contents()->create(
                $repoOwner,
                $repoName,
                $filePath,
                base64_encode($content),
                $message,
                $branch
            );

            return $response['content']['download_url'] ?? '';
        } catch (Exception $e) {
            throw new Exception("Échec de l'upload sur GitHub : " . $e->getMessage());
        }
    }

    private function sendReferralEmail(
        User $user,
        string $referralCode,
        string $qrCodePath,
        MailerInterface $mailer
    ): void {
        $referralLink = $this->generateUrl('app_register', ['ref' => $referralCode], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@dcsm-commerce.com', 'DCSM COMMERCE'))
            ->to($user->getEmail())
            ->subject('Votre QR Code et lien d\'affiliation')
            ->htmlTemplate('emails/referral_email.html.twig')
            ->context([
                'user' => $user,
                'referralLink' => $referralLink,
                'qrCodePath' => $qrCodePath,
            ]);

        try {
            $mailer->send($email);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Échec de l\'envoi de l\'email : ' . $e->getMessage());
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
}