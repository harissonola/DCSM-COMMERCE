<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

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
    ): Response {
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

            // Gestion des mises à jour
            $this->handleUsernameUpdate($request, $user, $errors, $updatedFields);
            $this->handleEmailUpdate($request, $user, $errors, $updatedFields);
            $this->handleAvatarUpload($request, $user, $errors, $updatedFields);
            $this->handlePasswordUpdate($request, $user, $passwordHasher, $errors, $updatedFields);
            $this->handleNotifications($request, $user, $updatedFields);
            $this->handleNameUpdate($request, $user, $errors, $updatedFields); // Ajout de la gestion des noms

            // Si des erreurs ont été accumulées
            if (!empty($errors)) {
                // On lève une exception pour la gérer dans le catch
                throw new \Exception(implode(', ', $errors), 422);
            }

            // Enregistrement en base
            $entityManager->flush();

            // Si tout va bien
            return $this->handleSuccessResponse($request, 'Mises à jour effectuées avec succès');
        } catch (\Exception $e) {
            // Si on a une erreur
            return $this->handleErrorResponse($request, $e);
        }
    }

    private function handleUsernameUpdate(Request $request, User $user, array &$errors, array &$updatedFields): void
    {
        if ($request->request->has('username')) {
            $username = $request->request->get('username');
            if (empty($username)) {
                $errors[] = 'Le nom d\'utilisateur ne peut pas être vide';
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
                $errors[] = 'Adresse email invalide';
            } else {
                $user->setEmail($email);
                $updatedFields[] = 'email';
            }
        }
    }

    private function handleAvatarUpload(Request $request, User $user, array &$errors, array &$updatedFields): void
    {
        if ($request->files->has('imageFile')) {
            $file = $request->files->get('imageFile');

            // Validation du fichier
            if (!$file->isValid()) {
                $errors[] = 'Fichier invalide : ' . $file->getErrorMessage();
                return;
            }

            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
            if (!in_array($file->getMimeType(), $allowedTypes)) {
                $errors[] = 'Type de fichier non supporté (JPEG, PNG, GIF seulement)';
                return;
            }

            if ($file->getSize() > 2 * 1024 * 1024) { // 2MB
                $errors[] = 'Le fichier est trop volumineux (max 2MB)';
                return;
            }

            $user->setImageFile($file);
            $updatedFields[] = 'photo';
        }
    }

    private function handlePasswordUpdate(
        Request $request,
        User $user,
        UserPasswordHasherInterface $passwordHasher,
        array &$errors,
        array &$updatedFields
    ): void {
        // On vérifie s'il y a un champ currentPassword pour savoir si l'utilisateur
        // veut changer le mot de passe
        if (!$request->request->has('currentPassword')) {
            return;
        }

        $currentPassword = $request->request->get('currentPassword');
        $newPassword = $request->request->get('newPassword');
        $confirmPassword = $request->request->get('confirmPassword');

        if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
            $errors[] = 'Mot de passe actuel incorrect';
        }

        if ($newPassword !== $confirmPassword) {
            $errors[] = 'Les mots de passe ne correspondent pas';
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

    /**
     * Gère la mise à jour du prénom (fname) et du nom (lname).
     */
    private function handleNameUpdate(Request $request, User $user, array &$errors, array &$updatedFields): void
    {
        if ($request->request->has('fname') || $request->request->has('lname')) {
            $fname = $request->request->get('fname');
            $lname = $request->request->get('lname');

            if (empty($fname)) {
                $errors[] = 'Le prénom ne peut pas être vide';
            }

            if (empty($lname)) {
                $errors[] = 'Le nom ne peut pas être vide';
            }

            if (empty($errors)) {
                $user->setFname($fname);
                $user->setLname($lname);
                $updatedFields[] = 'fname';
                $updatedFields[] = 'lname';
            }
        }
    }

    /**
     * Gère la réponse en cas de succès :
     * - Si requête AJAX => JSON
     * - Sinon => addFlash() + redirect
     */
    private function handleSuccessResponse(Request $request, string $message): Response
    {
        if ($request->isXmlHttpRequest()) {
            // Requête AJAX => JSON
            return $this->json([
                'success' => true,
                'message' => $message
            ]);
        }

        // Requête classique => flash + redirection
        $this->addFlash('success', $message);
        return $this->redirectToRoute('app_user_settings');
    }

    /**
     * Gère la réponse en cas d'erreur :
     * - Si requête AJAX => JSON
     * - Sinon => addFlash() + redirect
     */
    private function handleErrorResponse(Request $request, \Exception $exception): Response
    {
        if ($request->isXmlHttpRequest()) {
            // Requête AJAX => JSON
            return $this->json([
                'success' => false,
                'message' => $exception->getMessage()
            ], ($exception->getCode() > 0 ? $exception->getCode() : 400));
        }

        // Requête classique => flash + redirection
        $this->addFlash('error', $exception->getMessage());
        return $this->redirectToRoute('app_user_settings');
    }
}