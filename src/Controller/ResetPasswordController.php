<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Controller\ResetPasswordControllerTrait;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

#[Route('/reset-password')]
class ResetPasswordController extends AbstractController
{
    use ResetPasswordControllerTrait;

    public function __construct(
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('', name: 'app_forgot_password_request')]
    public function request(Request $request, MailerInterface $mailer, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $user = $this->entityManager->getRepository(User::class)->findOneBy([
                'email' => $email,
                'isVerified' => true // Assure que l'email est vérifié
            ]);

            if (!$user) {
                $message = $translator->trans('Aucun compte vérifié n\'est associé à cet email.');

                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $message
                    ], Response::HTTP_NOT_FOUND);
                }

                $this->addFlash('error', $message);
                return $this->redirectToRoute('app_forgot_password_request');
            }

            try {
                $resetToken = $this->resetPasswordHelper->generateResetToken($user);
            } catch (ResetPasswordExceptionInterface $e) {
                $errorMessage = $translator->trans($e->getReason(), [], 'ResetPasswordBundle');

                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse([
                        'success' => false,
                        'message' => $errorMessage
                    ], Response::HTTP_BAD_REQUEST);
                }

                $this->addFlash('error', $errorMessage);
                return $this->redirectToRoute('app_forgot_password_request');
            }

            $email = (new TemplatedEmail())
                ->from(new Address('no-reply@bictrary.com', 'Bictrary'))
                ->to($user->getEmail())
                ->subject($translator->trans('Réinitialisation de votre mot de passe'))
                ->htmlTemplate('reset_password/email.html.twig')
                ->context([
                    'resetToken' => $resetToken,
                    'user' => $user,
                    'tokenLifetime' => $this->resetPasswordHelper->getTokenLifetime(),
                ]);

            $mailer->send($email);
            $this->setTokenObjectInSession($resetToken);

            if ($request->isXmlHttpRequest()) {
                return new JsonResponse([
                    'success' => true,
                    'message' => $translator->trans('Un email de réinitialisation a été envoyé.')
                ]);
            }

            return $this->redirectToRoute('app_check_email');
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form->createView(),
        ]);
    }

    #[Route('/check-email', name: 'app_check_email')]
    public function checkEmail(): Response
    {
        $resetToken = $this->getTokenObjectFromSession();
        
        if (null === $resetToken) {
            return $this->redirectToRoute('app_forgot_password_request');
        }

        return $this->render('reset_password/check_email.html.twig', [
            'resetToken' => $resetToken,
            'tokenLifetime' => $this->resetPasswordHelper->getTokenLifetime(),
        ]);
    }

    #[Route('/reset/{token}', name: 'app_reset_password')]
    public function reset(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        TranslatorInterface $translator,
        ?string $token = null
    ): Response {
        if ($token) {
            $this->storeTokenInSession($token);
            return $this->redirectToRoute('app_reset_password');
        }

        $token = $this->getTokenFromSession();
        if (null === $token) {
            $this->addFlash('error', $translator->trans('Aucun token de réinitialisation trouvé.'));
            return $this->redirectToRoute('app_forgot_password_request');
        }

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $this->addFlash('error', $translator->trans(
                'Il y a eu un problème avec votre demande de réinitialisation - %s',
                ['%s' => $translator->trans($e->getReason(), [], 'ResetPasswordBundle')]
            ));

            return $this->redirectToRoute('app_forgot_password_request');
        }

        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->resetPasswordHelper->removeResetRequest($token);

            $encodedPassword = $passwordHasher->hashPassword(
                $user,
                $form->get('plainPassword')->getData()
            );

            $user->setPassword($encodedPassword);
            $this->entityManager->flush();

            $this->cleanSessionAfterReset();

            $this->addFlash('success', $translator->trans('Votre mot de passe a été réinitialisé avec succès.'));

            return $this->redirectToRoute('app_login');
        }

        return $this->render('reset_password/reset.html.twig', [
            'resetForm' => $form->createView(),
        ]);
    }
}