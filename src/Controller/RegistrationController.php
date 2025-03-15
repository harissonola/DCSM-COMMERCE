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
                $referralCode = uniqid('ref_');
                $user->setReferralCode($referralCode);

                $referredBy = $form->get('referredBy')->getData();
                if ($referredBy) {
                    $referrer = $entityManager->getRepository(User::class)->findOneBy(['referralCode' => $referredBy]);
                    if ($referrer) {
                        $user->setReferredBy($referredBy);
                    }
                }

                $user->setPassword($userPasswordHasher->hashPassword($user, $plainPassword));
                $user->setCreatedAt(new \DateTimeImmutable())
                     ->setMiningBotActive(0);

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

                $referralLink = $urlGenerator->generate(
                    'app_register', 
                    ['ref' => $referralCode], 
                    UrlGeneratorInterface::ABSOLUTE_URL
                );
                $qrCode = new QrCode($referralLink);
                $writer = new PngWriter();
                $qrResult = $writer->write($qrCode);

                $qrCodeDirectory = $this->getParameter('qr_code_directory');
                if (!is_dir($qrCodeDirectory)) {
                    mkdir($qrCodeDirectory, 0755, true);
                }
                $qrCodeFileName = $referralCode . '.png';
                $filePath = $qrCodeDirectory . '/' . $qrCodeFileName;
                file_put_contents($filePath, $qrResult->getString());

                $publicQrCodeUrl = '/uploads/qr_codes/' . $qrCodeFileName;
                $user->setQrCodePath($publicQrCodeUrl);
                $entityManager->flush();

                $this->emailVerifier->sendEmailConfirmation(
                    'app_verify_email',
                    $user,
                    (new TemplatedEmail())
                        ->from(new Address('no-reply@dcsm-commerce.com', 'DCSM COMMERCE'))
                        ->to((string) $user->getEmail())
                        ->subject('Confirmer votre adresse mail')
                        ->htmlTemplate('registration/confirmation_email.html.twig')
                );

                $this->sendReferralEmail($user, $referralLink, $publicQrCodeUrl, $mailer);

                return $security->login($user, AppAuthenticator::class, 'main');
            }
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }
}