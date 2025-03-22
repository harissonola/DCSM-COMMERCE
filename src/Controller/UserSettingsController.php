<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class UserSettingsController extends AbstractController
{
    #[Route('/user/settings', name: 'app_user_settings')]
    public function index(): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('user_settings/index.html.twig');
    }

    #[Route('/user/settings/update', name: 'app_user_settings_update', methods: ['POST'])]
    public function update(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        try {
            /** @var User $user */
            $user = $this->getUser();

            if (!$user) {
                throw new \Exception('Utilisateur non authentifié.', 401);
            }

            // Vérification CSRF
            $submittedToken = $request->request->get('_csrf_token');
            if (!$this->isCsrfTokenValid('update_settings', $submittedToken)) {
                throw new \Exception('Token CSRF invalide.');
            }

            $errors = [];
            $updatedFields = [];

            // Mise à jour username avec validation
            $this->handleUsernameUpdate($request, $user, $errors, $updatedFields);

            // Mise à jour email avec validation
            $this->handleEmailUpdate($request, $user, $errors, $updatedFields);

            // Gestion avatar avec VichUploader
            $this->handleAvatarUpload($request, $user, $errors, $updatedFields);

            // Mise à jour mot de passe avec validation
            $this->handlePasswordUpdate($request, $user, $passwordHasher, $errors, $updatedFields);

            // Mise à jour des préférences
            $this->handleNotifications($request, $user, $updatedFields);

            if (!empty($errors)) {
                return $this->jsonErrorResponse($errors);
            }

            $entityManager->flush();

            return new JsonResponse([
                'status' => 'success',
                'message' => 'Mises à jour effectuées avec succès',
                'photo' => $user->getPhoto(),
                'updatedFields' => array_unique($updatedFields)
            ]);
        } catch (\Exception $e) {
            return $this->jsonExceptionResponse($e, $errors ?? []);
        }
    }

    private function handleUsernameUpdate(Request $request, User $user, array &$errors, array &$updatedFields): void
    {
        if ($request->request->has('username')) {
            $username = $request->request->get('username');
            if (empty($username)) {
                $errors['username'] = 'Le nom d\'utilisateur ne peut pas être vide';
            } else {
                $user->setUsername($username);
                $updatedFields[] = 'username';
            }
        }
    }

    private function handleEmailUpdate(Request $request, User $user, array &$errors, array &$updatedFields): void
    {
        if ($request->request->has('email')) {
            $email = $request->request->get('email');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['email'] = 'Adresse email invalide';
            } else {
                $user->setEmail($email);
                $updatedFields[] = 'email';
            }
        }
    }

    private function handleAvatarUpload(Request $request, User $user, array &$errors, array &$updatedFields): void
    {
        $avatarFile = $request->files->get('avatar');
        if (!$avatarFile) return;

        $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'image/tiff',
            'image/bmp'
        ];

        $mimeType = $avatarFile->getMimeType();
        $fileInfo = getimagesize($avatarFile->getPathname());

        // Validation MIME type
        if (!$fileInfo || !in_array($mimeType, $allowedMimeTypes)) {
            $errors['avatar'] = 'Format d\'image non supporté';
            return;
        }

        // Sécurité SVG
        if ($mimeType === 'image/svg+xml') {
            $svgContent = file_get_contents($avatarFile->getPathname());
            if (preg_match('/<script/i', $svgContent)) {
                $errors['avatar'] = 'SVG dangereux détecté';
                return;
            }
        }

        $user->setPhoto($avatarFile);
        $updatedFields[] = 'avatar';
    }

    private function handlePasswordUpdate(
        Request $request,
        User $user,
        UserPasswordHasherInterface $passwordHasher,
        array &$errors,
        array &$updatedFields
    ): void {
        if (!$request->request->has('currentPassword')) return;

        $currentPassword = $request->request->get('currentPassword');
        $newPassword = $request->request->get('newPassword');
        $confirmPassword = $request->request->get('confirmPassword');

        if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
            $errors['currentPassword'] = 'Mot de passe actuel incorrect';
        }

        if ($newPassword !== $confirmPassword) {
            $errors['confirmPassword'] = 'Les mots de passe ne correspondent pas';
        }

        if (empty($errors)) {
            $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            $updatedFields[] = 'password';
        }
    }

    private function handleNotifications(Request $request, User $user, array &$updatedFields): void
    {
        $user->setEmailNotifications(
            $request->request->getBoolean('emailNotifications', false)
        );
        $updatedFields[] = 'notifications';
    }

    private function jsonErrorResponse(array $errors): JsonResponse
    {
        return new JsonResponse([
            'status' => 'error',
            'message' => 'Des erreurs de validation sont survenues',
            'errors' => $errors
        ], 400);
    }

    private function jsonExceptionResponse(\Exception $e, array $errors): JsonResponse
    {
        return new JsonResponse([
            'status' => 'error',
            'message' => $e->getMessage(),
            'errors' => $errors
        ], $e->getCode() ?: 400);
    }
}
