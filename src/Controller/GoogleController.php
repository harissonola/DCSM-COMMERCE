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
    public function connectGoogle(ClientRegistry $clientRegistry, Request $request): Response
    {
        // Stocker le paramètre ref dans la session avant la redirection vers Google
        $ref = $request->query->get('ref');
        if ($ref) {
            $request->getSession()->set('google_referral_code', $ref);
        }

        return $clientRegistry
            ->getClient('google')
            ->redirect(['profile', 'email']);
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

        // Recherche par googleId ou email
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

        // Création d'un nouvel utilisateur
        $user = new User();
        $referralCode = uniqid('ref_', false);

        try {
            $user
                ->setGoogleId($googleId)
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

            $user->setAgreeTerms(true); // Accepte automatiquement les CGU
            
            // Gestion du parrainage
            $referredBy = $request->getSession()->get('google_referral_code');
            if ($referredBy) {
                $this->handleReferralSystem($user, $referredBy, $entityManager);
                $request->getSession()->remove('google_referral_code');
            }

            // Génération et upload du QR Code
            $this->generateAndSaveQrCode($user, $urlGenerator, $entityManager);

            $entityManager->persist($user);
            $entityManager->flush();

            // Envoi de l'email
            $this->sendReferralEmail($user, $mailer, $urlGenerator, $request);

            return $authenticator->authenticateUser($user, $appAuthenticator, $request);
        } catch (Exception $e) {
            $session = $request->getSession();
            $session->getFlashBag()->add('error', "Erreur lors de la création du compte : " . $e->getMessage());
            $entityManager->clear();
            return $this->redirectToRoute('app_register');
        }
    }

    private function handleReferralSystem(User $user, string $referredBy, EntityManagerInterface $entityManager): void
    {
        $referrer = $entityManager->getRepository(User::class)
            ->findOneBy(['referralCode' => $referredBy]);

        if ($referrer) {
            $referrer->addReferral($user);
            // Ajouter directement 10% au solde du parrain
            $referrer->setBalance($referrer->getBalance() + 10);
            $entityManager->flush();
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

            $qrCode = new QrCode($referralLink);
            $writer = new PngWriter([
                'errorCorrectionLevel' => 'L',
                'size' => 300
            ]);
            $qrResult = $writer->write($qrCode);

            $tempDir = $this->getParameter('kernel.project_dir') . '/var/tmp/';
            if (!$this->filesystem->exists($tempDir)) {
                $this->filesystem->mkdir($tempDir, 0755);
            }
            $tempFilePath = $tempDir . $user->getReferralCode() . '.png';
            $qrResult->saveToFile($tempFilePath);

            $fileContent = file_get_contents($tempFilePath);
            $filePath = "uploads/qrcodes/{$user->getReferralCode()}.png";
            $cdnUrl = $this->uploadToGitHub($filePath, $fileContent, 'QR Code via Google Login');

            $this->filesystem->remove($tempFilePath);

            $user->setQrCodePath($cdnUrl);
            $entityManager->flush();
        } catch (Exception $e) {
            $session = $request->getSession();
            $session->getFlashBag()->add('warning', "Échec génération QR Code : " . $e->getMessage());
        }
    }

    private function uploadToGitHub(string $filePath, string $content, string $message): string
    {
        $repoOwner = 'harissonola';
        $repoName = 'my-cdn';
        $branch = 'main';

        $this->githubClient->authenticate($_ENV['GH_TOKEN_BASE64'], null, Client::AUTH_ACCESS_TOKEN);

        $ref = $this->githubClient->api('git')->references()->show($repoOwner, $repoName, 'heads/' . $branch);
        $currentSha = $ref['object']['sha'];
        $commit = $this->githubClient->api('git')->commits()->show($repoOwner, $repoName, $currentSha);
        $treeSha = $commit['tree']['sha'];

        $blob = $this->githubClient->api('git')->blobs()->create($repoOwner, $repoName, [
            'content' => base64_encode($content),
            'encoding' => 'base64',
        ]);

        $tree = $this->githubClient->api('git')->trees()->create($repoOwner, $repoName, [
            'base_tree' => $treeSha,
            'tree' => [[
                'path' => $filePath,
                'mode' => '100644',
                'type' => 'blob',
                'sha' => $blob['sha'],
            ]]
        ]);

        $newCommit = $this->githubClient->api('git')->commits()->create($repoOwner, $repoName, [
            'message' => $message,
            'tree' => $tree['sha'],
            'parents' => [$currentSha],
        ]);

        $this->githubClient->api('git')->references()->update($repoOwner, $repoName, 'heads/' . $branch, [
            'sha' => $newCommit['sha'],
        ]);

        return "https://raw.githubusercontent.com/{$repoOwner}/{$repoName}/{$branch}/{$filePath}";
    }

    private function sendReferralEmail(
        User $user,
        MailerInterface $mailer,
        UrlGeneratorInterface $urlGenerator,
        Request $request
    ): void {
        try {
            $email = (new TemplatedEmail())
                ->from(new Address('no-reply@bictrary.com', 'Bictrary'))
                ->to($user->getEmail())
                ->subject('Votre QR Code et lien d\'affiliation')
                ->htmlTemplate('emails/referral_email.html.twig')
                ->context([
                    'user' => $user,
                    'referralLink' => $urlGenerator->generate(
                        'app_register',
                        ['ref' => $user->getReferralCode()],
                        UrlGeneratorInterface::ABSOLUTE_URL
                    ),
                    'qrCodePath' => $user->getQrCodePath(),
                    'app_name' => 'Bictrary',
                ]);

            $mailer->send($email);
        } catch (Exception $e) {
            $session = $request->getSession();
            $session->getFlashBag()->add('error', 'Échec de l\'envoi de l\'email : ' . $e->getMessage());
        }
    }
}