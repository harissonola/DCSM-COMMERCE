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
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Component\Mailer\MailerInterface;

class RegistrationController extends AbstractController
{
    public function __construct(private EmailVerifier $emailVerifier) {}

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
                // Génération d'un code d'affiliation
                $referralCode = uniqid('ref_');
                $user->setReferralCode($referralCode);

                $referredBy = $form->get('referredBy')->getData();
                if ($referredBy) {
                    $referrer = $entityManager->getRepository(User::class)->findOneBy(['referralCode' => $referredBy]);
                    if ($referrer) {
                        $user->setReferredBy($referredBy);
                    }
                }

                // Hashage du mot de passe et configuration de l'utilisateur
                $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
                $user->setCreatedAt(new \DateTimeImmutable())
                     ->setMiningBotActive(0);

                // Traitement de l'image (stockage local)
                $image = $form->get('photo')->getData();
                if ($image) {
                    $newFilename = uniqid() . '.' . $image->guessExtension();
                    try {
                        $image->move($this->getParameter('users_img_directory'), $newFilename);
                    } catch (FileException $e) {
                        $this->addFlash('error', 'Erreur lors de l\'upload de l\'image.');
                        return $this->redirectToRoute('app_register');
                    }
                    $user->setPhoto($newFilename);
                } else {
                    $user->setPhoto("default" . rand(1, 7) . ".jpg");
                }

                $entityManager->persist($user);
                $entityManager->flush();

                // Générer le lien d'affiliation
                $referralLink = $urlGenerator->generate(
                    'app_register', 
                    ['ref' => $referralCode], 
                    UrlGeneratorInterface::ABSOLUTE_URL
                );
                $qrCode = new QrCode($referralLink);
                $writer = new PngWriter();
                $qrResult = $writer->write($qrCode);

                // Stockage du QR Code sur FTP (seul le QR code est envoyé sur FTP)
                $ftpServer   = "ftpupload.net";
                $ftpUsername = "if0_38455727";
                $ftpPassword = "DCSMCOMMERCE";
                // Répertoire FTP : assurez-vous que le chemin correspond à votre configuration
                $ftpDirectory = "/htdocs/uploads/users/";
                $qrCodeFileName = $referralCode . '.png';

                // Connexion FTP sur le port 21
                $ftpConnection = ftp_connect($ftpServer, 21);
                if (!$ftpConnection) {
                    throw new \Exception('Impossible de se connecter au serveur FTP.');
                }

                $loginResult = ftp_login($ftpConnection, $ftpUsername, $ftpPassword);
                if (!$loginResult) {
                    throw new \Exception('Échec de la connexion FTP.');
                }

                // Pour forcer l'utilisation du mode actif (stockage sur le port 21)
                ftp_pasv($ftpConnection, false);

                // Vérifier si le répertoire existe, sinon le créer
                if (!ftp_chdir($ftpConnection, $ftpDirectory)) {
                    if (!ftp_mkdir($ftpConnection, $ftpDirectory)) {
                        throw new \Exception('Impossible de créer le répertoire sur le serveur FTP.');
                    }
                    ftp_chdir($ftpConnection, $ftpDirectory); // Accéder au répertoire
                }

                // Créer un fichier temporaire sur le serveur local pour l'upload
                $tempFilePath = '/tmp/' . $qrCodeFileName;
                file_put_contents($tempFilePath, $qrResult->getString());

                // Uploader le QR Code sur le serveur FTP
                $uploadResult = ftp_put($ftpConnection, $qrCodeFileName, $tempFilePath, FTP_BINARY);
                if (!$uploadResult) {
                    throw new \Exception('L\'upload du fichier a échoué.');
                }

                // Fermer la connexion FTP et supprimer le fichier temporaire
                ftp_close($ftpConnection);
                unlink($tempFilePath);

                // Construire l'URL publique du QR Code
                $publicQrCodeUrl = 'http://daniel-project-cdn.free.nf/uploads/users/' . $qrCodeFileName;
                $user->setQrCodePath($publicQrCodeUrl);
                $entityManager->flush();

                // Envoi de l'email de confirmation d'inscription
                $this->emailVerifier->sendEmailConfirmation(
                    'app_verify_email',
                    $user,
                    (new TemplatedEmail())
                        ->from(new Address('no-reply@dcsm-commerce.com', 'DCSM COMMERCE'))
                        ->to((string) $user->getEmail())
                        ->subject('Confirmer votre adresse mail')
                        ->htmlTemplate('registration/confirmation_email.html.twig')
                );

                // Envoi de l'email de parrainage (incluant le lien d'affiliation et le QR code)
                $this->sendReferralEmail($user, $referralLink, $publicQrCodeUrl, $mailer);

                return $security->login($user, AppAuthenticator::class, 'main');
            }
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    private function sendReferralEmail(User $user, string $referralLink, string $qrCodeUrl, MailerInterface $mailer): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@dcsm-commerce.com', 'DCSM COMMERCE'))
            ->to($user->getEmail())
            ->subject('Votre lien d\'affiliation et QR Code')
            ->htmlTemplate('emails/referral_email.html.twig')
            ->context([
                'user' => $user,
                'referralLink' => $referralLink,
                'qrCodeUrl' => $qrCodeUrl,
            ]);
        
        $mailer->send($email);
    }

    // Les routes pour la vérification d'email restent inchangées
    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, TranslatorInterface $translator): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        try {
            $user = $this->getUser();
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('verify_email_error', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));
            return $this->redirectToRoute('app_register');
        }

        $this->addFlash('success', 'Votre adresse e-mail a été vérifiée.');
        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/verify/emailSend', name: 'app_verify_email_send')]
    public function send(): Response
    {
        $user = $this->getUser();
        
        if (!$user) {
            $this->addFlash('error', 'Vous devez être connecté pour vérifier votre email.');
            return $this->redirectToRoute('app_login');
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

        return $this->redirectToRoute('app_dashboard');
    }
}