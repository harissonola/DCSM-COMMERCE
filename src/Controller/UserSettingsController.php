<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class UserSettingsController extends AbstractController
{
    private string $uploadDirectory;

    public function __construct(ParameterBagInterface $params)
    {
        $this->uploadDirectory = $params->get('users_img_directory');
    }

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
        UserPasswordHasherInterface $passwordHasher,
        SluggerInterface $slugger
    ): JsonResponse {
        try {
            /** @var User $user */
            $user = $this->getUser();
            
            if (!$user) {
                throw new \Exception('Utilisateur non authentifié.', 401);
            }

            // Vérification du token CSRF
            $submittedToken = $request->request->get('_csrf_token');
            if (!$this->isCsrfTokenValid('update_settings', $submittedToken)) {
                throw new \Exception('Token CSRF invalide.');
            }

            $errors = [];
            $updatedFields = [];

            // Mise à jour des informations de profil
            if ($request->request->has('username')) {
                $username = $request->request->get('username');
                if (empty($username)) {
                    $errors['username'] = 'Le nom d\'utilisateur ne peut pas être vide';
                } else {
                    $user->setUsername($username);
                    $updatedFields[] = 'username';
                }
            }

            if ($request->request->has('email')) {
                $email = $request->request->get('email');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'Adresse email invalide';
                } else {
                    $user->setEmail($email);
                    $updatedFields[] = 'email';
                }
            }

            // Gestion de l'avatar
            $avatarFile = $request->files->get('avatar');
            if ($avatarFile) {
                if (!in_array($avatarFile->getMimeType(), ['image/jpeg', 'image/png', 'image/gif'])) {
                    $errors['avatar'] = 'Format d\'image non supporté (JPEG, PNG, GIF uniquement)';
                } elseif ($avatarFile->getSize() > 2 * 1024 * 1024) {
                    $errors['avatar'] = 'La taille de l\'image ne doit pas dépasser 2Mo';
                } else {
                    if (!is_dir($this->uploadDirectory)) {
                        mkdir($this->uploadDirectory, 0775, true);
                    }

                    $newFilename = $slugger->slug($user->getUsername()).'-'.uniqid().'.'.$avatarFile->guessExtension();
                    $avatarFile->move($this->uploadDirectory, $newFilename);
                    $user->setPhoto($newFilename);
                    $updatedFields[] = 'avatar';
                }
            }

            // Mise à jour du mot de passe
            if ($request->request->has('currentPassword')) {
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

            // Notifications
            $user->setEmailNotifications(
                $request->request->getBoolean('emailNotifications', false)
            );
            $updatedFields[] = 'notifications';

            if (!empty($errors)) {
                throw new \Exception('Validation failed');
            }

            $entityManager->flush();

            return new JsonResponse([
                'status' => 'success',
                'message' => 'Mises à jour effectuées avec succès',
                'photo' => $user->getPhoto(),
                'updatedFields' => $updatedFields
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => $errors ?? []
            ], 400);
        }
    }
}