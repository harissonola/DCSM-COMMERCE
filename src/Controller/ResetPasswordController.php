<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\ResetPasswordRequestFormType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
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
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {}

    /**
     * Demande de réinitialisation de mot de passe.
     */
    #[Route('', name: 'app_forgot_password_request', methods: ['GET', 'POST'])]
    public function request(Request $request, MailerInterface $mailer, TranslatorInterface $translator): Response
    {
        $form = $this->createForm(ResetPasswordRequestFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $email = $form->get('email')->getData();
            $user = $this->entityManager->getRepository(User::class)->findOneBy([
                'email' => $email,
                'isVerified' => true // Vérifie que l'utilisateur est vérifié
            ]);

            if (!$user) {
                $message = $translator->trans('Aucun compte vérifié n\'est associé à cet email.');
                return $this->handleErrorResponse($message, $request, Response::HTTP_NOT_FOUND);
            }

            try {
                $resetToken = $this->resetPasswordHelper->generateResetToken($user);
            } catch (ResetPasswordExceptionInterface $e) {
                $errorMessage = $translator->trans($e->getReason(), [], 'ResetPasswordBundle');
                $this->logger->error('Erreur lors de la génération du token de réinitialisation : ' . $errorMessage);
                return $this->handleErrorResponse($errorMessage, $request, Response::HTTP_BAD_REQUEST);
            }

            try {
                $emailMessage = (new TemplatedEmail())
                    ->from(new Address('no-reply@bictrary.com', 'Bictrary'))
                    ->to($user->getEmail())
                    ->subject($translator->trans('Réinitialisation de votre mot de passe'))
                    ->htmlTemplate('reset_password/email.html.twig')
                    ->context([
                        'resetToken' => $resetToken,
                        'user' => $user,
                        'tokenLifetime' => $this->resetPasswordHelper->getTokenLifetime(),
                    ]);

                $mailer->send($emailMessage);
                $this->setTokenObjectInSession($resetToken);

                $successMessage = $translator->trans('Un email de réinitialisation a été envoyé.');
                return $this->handleSuccessResponse($successMessage, $request, 'app_check_email');
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Erreur lors de l\'envoi de l\'email de réinitialisation : ' . $e->getMessage());
                $this->addFlash('error', $translator->trans('Une erreur est survenue lors de l\'envoi de l\'email.'));
                return $this->redirectToRoute('app_forgot_password_request');
            }
        }

        return $this->render('reset_password/request.html.twig', [
            'requestForm' => $form->createView(),
        ]);
    }

    /**
     * Affiche la page de confirmation après l'envoi de l'email.
     */
    #[Route('/check-email', name: 'app_check_email', methods: ['GET'])]
    public function checkEmail(): Response
    {
        $resetToken = $this->getTokenObjectFromSession();

        if (!$resetToken) {
            $this->logger->warning('Tentative d\'accès à la page de confirmation sans token.');
            return $this->redirectToRoute('app_forgot_password_request');
        }

        return $this->render('reset_password/check_email.html.twig', [
            'resetToken' => $resetToken,
            'tokenLifetime' => $this->resetPasswordHelper->getTokenLifetime(),
        ]);
    }

    /**
     * Réinitialisation du mot de passe via le token.
     */
    #[Route('/reset/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
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
        if (!$token) {
            $this->logger->warning('Tentative de réinitialisation sans token valide.');
            $this->addFlash('error', $translator->trans('Aucun token de réinitialisation trouvé.'));
            return $this->redirectToRoute('app_forgot_password_request');
        }

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            $errorMessage = $translator->trans(
                'Il y a eu un problème avec votre demande de réinitialisation - %s',
                ['%s' => $translator->trans($e->getReason(), [], 'ResetPasswordBundle')]
            );
            $this->logger->error('Erreur lors de la validation du token : ' . $errorMessage);
            $this->addFlash('error', $errorMessage);
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

    /**
     * Gère les réponses d'erreur pour les requêtes AJAX ou les redirections classiques.
     */
    private function handleErrorResponse(string $message, Request $request, int $statusCode): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => false, 'message' => $message], $statusCode);
        }

        $this->addFlash('error', $message);
        return $this->redirectToRoute('app_forgot_password_request');
    }

    /**
     * Gère les réponses de succès pour les requêtes AJAX ou les redirections classiques.
     */
    private function handleSuccessResponse(string $message, Request $request, string $redirectRoute): Response
    {
        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => true, 'message' => $message]);
        }

        return $this->redirectToRoute($redirectRoute);
    }
}