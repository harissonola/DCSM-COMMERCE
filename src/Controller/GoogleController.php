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

        // Vérification de l'existence de l'utilisateur
        $existingUser = $entityManager->getRepository(User::class)
            ->findOneBy(['googleId' => $googleId])
            ?? $entityManager->getRepository(User::class)->findOneBy(['email' => $email]);

        if ($existingUser) {
            // S'il n'a pas de GoogleId déjà renseigné, on le met à jour
            if (!$existingUser->getGoogleId()) {
                $existingUser->setGoogleId($googleId);
                $entityManager->flush();
            }
            return $authenticator->authenticateUser($existingUser, $appAuthenticator, $request);
        }

        // Création du nouvel utilisateur
        $user = new User();
        $referralCode = uniqid('ref_', false); // Code de parrainage sans point

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

            // Gestion du système de parrainage en passant l'objet Request
            $this->handleReferralSystem($user, $request, $entityManager);

            // Génération et upload du QR Code
            $this->generateAndSaveQrCode($user, $urlGenerator, $entityManager);

            // Sauvegarde en base
            $entityManager->persist($user);
            $entityManager->flush();

            // Envoi de l'email de parrainage
            $this->sendReferralEmail($user, $user->getReferralCode(), $user->getQrCodePath(), $mailer);

            return $authenticator->authenticateUser($user, $appAuthenticator, $request);
        } catch (Exception $e) {
            $this->addFlash('error', "Erreur lors de la création du compte : " . $e->getMessage());
            $entityManager->clear();
            return $this->redirectToRoute('app_register');
        }
    }

    /**
     * Récupère le code de parrainage depuis l'URL et le lie à l'utilisateur, en mettant à jour les récompenses du parrain.
     */
    private function handleReferralSystem(User $user, Request $request, EntityManagerInterface $entityManager): void
    {
        $referredBy = $request->query->get('ref');
        if ($referredBy) {
            $referrer = $entityManager->getRepository(User::class)->findOneBy(['referralCode' => $referredBy]);
            if ($referrer) {
                $referrer->addReferral($user);
                $this->updateReferrerRewards($referrer, $entityManager);

                $entityManager->persist($referrer);
                $entityManager->flush();
            }
        }
    }

    /**
     * Génère le QR Code du lien d'affiliation, l'upload sur GitHub et stocke l'URL dans l'entité User.
     */
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

            // Génération du QR Code
            $qrCode = new QrCode($referralLink);
            $writer = new PngWriter([
                'errorCorrectionLevel' => 'L',
                'size' => 300
            ]);
            $qrResult = $writer->write($qrCode);

            // Création d'un fichier temporaire
            $tempDir = $this->getParameter('kernel.project_dir') . '/var/tmp/';
            if (!$this->filesystem->exists($tempDir)) {
                $this->filesystem->mkdir($tempDir, 0755);
            }
            $tempFilePath = $tempDir . $user->getReferralCode() . '.png';
            $qrResult->saveToFile($tempFilePath);

            // Lecture du contenu binaire du fichier
            $fileContent = file_get_contents($tempFilePath);

            // Upload sur GitHub
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

    /**
     * Upload l'image sur GitHub en utilisant l'API Git Data pour créer un blob, un arbre, un commit,
     * et mettre à jour la référence de la branche.
     */
    private function uploadToGitHub(string $filePath, string $content, string $message): string
    {
        $repoOwner = 'harissonola';
        $repoName = 'my-cdn';
        $branch = 'main';

        try {
            // Authentification avec le token GitHub en dur
            $this->githubClient->authenticate("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", null, Client::AUTH_ACCESS_TOKEN);

            // Récupération de la référence de la branche
            $reference = $this->githubClient->api('git')->references()->show($repoOwner, $repoName, 'heads/' . $branch);
            $currentCommitSha = $reference['object']['sha'];

            // Récupération du commit courant pour obtenir l'arbre de base
            $commit = $this->githubClient->api('git')->commits()->show($repoOwner, $repoName, $currentCommitSha);
            $treeSha = $commit['tree']['sha'];

            // Création d'un blob avec le contenu encodé en base64
            $blob = $this->githubClient->api('git')->blobs()->create($repoOwner, $repoName, [
                'content' => base64_encode($content),
                'encoding' => 'base64'
            ]);

            // Création d'un nouvel arbre en ajoutant le blob
            $tree = $this->githubClient->api('git')->trees()->create($repoOwner, $repoName, [
                'base_tree' => $treeSha,
                'tree' => [
                    [
                        'path' => $filePath,
                        'mode' => '100644',
                        'type' => 'blob',
                        'sha' => $blob['sha']
                    ]
                ]
            ]);

            // Création d'un nouveau commit avec le nouvel arbre
            $newCommit = $this->githubClient->api('git')->commits()->create($repoOwner, $repoName, [
                'message' => $message,
                'tree' => $tree['sha'],
                'parents' => [$currentCommitSha]
            ]);

            // Mise à jour de la référence de la branche
            $this->githubClient->api('git')->references()->update($repoOwner, $repoName, 'heads/' . $branch, [
                'sha' => $newCommit['sha']
            ]);

            // Retour de l'URL raw du fichier stocké sur GitHub
            return "https://raw.githubusercontent.com/{$repoOwner}/{$repoName}/{$branch}/{$filePath}";
        } catch (Exception $e) {
            throw new Exception("Échec de l'upload sur GitHub : " . $e->getMessage());
        }
    }

    /**
     * Envoi d'un email de parrainage contenant le lien d'affiliation et le QR Code.
     */
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

    /**
     * Met à jour les récompenses du parrain en fonction du nombre de filleuls.
     */
    private function updateReferrerRewards(User $referrer, EntityManagerInterface $entityManager): void
    {
        $count = count($referrer->getReferrals());

        if ($count >= 40) {
            $referrer->setReferralRewardRate(0.13)
                ->setBalance($referrer->getBalance() + 10);
        } elseif ($count >= 20) {
            $referrer->setReferralRewardRate(0.10);
        } elseif ($count >= 10) {
            $referrer->setReferralRewardRate(0.7);
        } elseif ($count >= 5) {
            $referrer->setReferralRewardRate(0.6);
        }

        $entityManager->persist($referrer);
        $entityManager->flush();
    }
}