<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Security\AppAuthenticator;
use App\Security\EmailVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

class RegistrationController extends AbstractController
{
    private EmailVerifier $emailVerifier;
    private Client $githubClient;
    private Filesystem $filesystem;

    public function __construct(
        EmailVerifier $emailVerifier,
        Client $githubClient,
        Filesystem $filesystem
    ) {
        $this->emailVerifier = $emailVerifier;
        $this->githubClient = $githubClient;
        $this->filesystem = $filesystem;
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

        // Gestion du parrainage via le paramètre 'ref'
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
            ->setMiningBotActive(false)
            ->setBalance(0);

        // Upload de l'image de profil
        $this->handleProfileImageUpload($user, $form);

        // Gestion du système de parrainage
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

    private function generateAndSaveQrCode(
        User $user,
        UrlGeneratorInterface $urlGenerator,
        EntityManagerInterface $entityManager
    ): void {
        try {
            // Génération du lien d'affiliation
            $referralLink = $urlGenerator->generate(
                'app_register',
                ['ref' => $user->getReferralCode()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            // Génération locale du QR Code
            $tempDir = $this->getParameter('kernel.project_dir') . '/var/tmp/';
            if (!$this->filesystem->exists($tempDir)) {
                $this->filesystem->mkdir($tempDir, 0755);
            }

            $tempFilePath = $tempDir . $user->getReferralCode() . '.png';

            // Configuration du QR Code
            $qrCode = new QrCode($referralLink);
            $writer = new PngWriter([
                'errorCorrectionLevel' => 'L', // Niveau de correction 'Low'
                'size' => 300 // Taille 300x300 pixels
            ]);
            $qrResult = $writer->write($qrCode);
            $qrResult->save($tempFilePath);

            // Lecture du contenu du fichier temporaire
            $fileContent = file_get_contents($tempFilePath);

            // Upload sur GitHub avec la nouvelle méthode
            $githubPath = "uploads/qrcodes/{$user->getReferralCode()}.png";
            $cdnUrl = $this->uploadToGitHub($githubPath, $fileContent, 'QR Code via Registration');

            // Suppression du fichier temporaire
            $this->filesystem->remove($tempFilePath);

            // Enregistrement de l'URL dans l'entité
            $user->setQrCodePath($cdnUrl);
            $entityManager->flush();
        } catch (\Exception $e) {
            $this->addFlash('warning', "Échec de génération du QR Code : " . $e->getMessage());
        }
    }

    /**
     * Méthode mise à jour pour uploader un fichier sur GitHub en utilisant l'API Git Data.
     */
    private function uploadToGitHub(string $filePath, string $content, string $message): string
    {
        $repoOwner = 'harissonola';
        $repoName = 'my-cdn';
        $branch = 'main';

        try {
            // Authentification avec le token GitHub
            $this->githubClient->authenticate($_ENV['GITHUB_TOKEN'], null, Client::AUTH_ACCESS_TOKEN);

            // Récupérer la référence de la branche
            $reference = $this->githubClient->api('git')->references()->show($repoOwner, $repoName, 'heads/' . $branch);
            $currentCommitSha = $reference['object']['sha'];

            // Récupérer le commit courant pour obtenir l'arbre de base
            $commit = $this->githubClient->api('git')->commits()->show($repoOwner, $repoName, $currentCommitSha);
            $treeSha = $commit['tree']['sha'];

            // Créer un blob avec le contenu binaire encodé en base64
            $blob = $this->githubClient->api('git')->blobs()->create($repoOwner, $repoName, [
                'content' => base64_encode($content),
                'encoding' => 'base64'
            ]);

            // Créer un nouvel arbre en ajoutant le blob
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

            // Créer un nouveau commit avec le nouvel arbre
            $newCommit = $this->githubClient->api('git')->commits()->create($repoOwner, $repoName, [
                'message' => $message,
                'tree' => $tree['sha'],
                'parents' => [$currentCommitSha]
            ]);

            // Mettre à jour la référence de la branche pour pointer sur le nouveau commit
            $this->githubClient->api('git')->references()->update($repoOwner, $repoName, 'heads/' . $branch, [
                'sha' => $newCommit['sha']
            ]);

            // Retourner l'URL raw du fichier stocké sur GitHub
            return "https://raw.githubusercontent.com/{$repoOwner}/{$repoName}/{$branch}/{$filePath}";
        } catch (\Exception $e) {
            throw new \Exception("Échec de l'upload sur GitHub : " . $e->getMessage());
        }
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
                $entityManager->flush();
            }
        }
    }

    private function handleProfileImageUpload(User $user, $form): void
    {
        try {
            $image = $form->get('photo')->getData();
            if ($image instanceof UploadedFile) {
                // Gestion de l'image de profil
                $tempDir = $this->getParameter('kernel.project_dir') . '/var/tmp/';
                if (!$this->filesystem->exists($tempDir)) {
                    $this->filesystem->mkdir($tempDir, 0755);
                }

                $fileName = uniqid() . '.' . $image->guessExtension();
                $tempFilePath = $tempDir . $fileName;
                $image->move($tempDir, $fileName);

                // Lecture du contenu du fichier temporaire
                $fileContent = file_get_contents($tempFilePath);

                // Upload sur GitHub
                $githubPath = "uploads/profile/{$fileName}";
                $cdnUrl = $this->uploadToGitHub($githubPath, $fileContent, 'Upload photo profil');

                // Suppression du fichier temporaire
                $this->filesystem->remove($tempFilePath);

                $user->setPhoto($cdnUrl);
            } else {
                $user->setPhoto($this->getDefaultProfileImage());
            }
        } catch (\Exception $e) {
            $this->addFlash('warning', "Erreur d'upload : " . $e->getMessage());
            $user->setPhoto($this->getDefaultProfileImage());
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
                ->subject('Confirmation de votre adresse email')
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
                ]);
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->addFlash('error', 'Échec de l\'envoi de l\'email de parrainage.');
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

    private function getDefaultProfileImage(): string
    {
        return sprintf('https://cdn.jsdelivr.net/gh/harissonola/my-cdn@main/uploads/profile/default%d.jpg', rand(1, 7));
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
                    ->subject('Nouvelle confirmation de votre email')
                    ->htmlTemplate('registration/confirmation_email.html.twig')
            );
            $this->addFlash('success', 'Un nouveau lien de confirmation a été envoyé !');
        } catch (\Exception $e) {
            $this->addFlash('error', 'Erreur lors de l\'envoi : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_register');
    }
}