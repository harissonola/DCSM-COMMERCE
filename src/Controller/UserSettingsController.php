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

            // Vérification CSRF
            $submittedToken = $request->request->get('_csrf_token');
            if (!$this->isCsrfTokenValid('update_settings', $submittedToken)) {
                throw new \Exception('Token CSRF invalide.');
            }

            $errors = [];
            $updatedFields = [];

            // Mise à jour username
            if ($request->request->has('username')) {
                $username = $request->request->get('username');
                if (empty($username)) {
                    $errors['username'] = 'Le nom d\'utilisateur ne peut pas être vide';
                } else {
                    $user->setUsername($username);
                    $updatedFields[] = 'username';
                }
            }

            // Mise à jour email
            if ($request->request->has('email')) {
                $email = $request->request->get('email');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors['email'] = 'Adresse email invalide';
                } else {
                    $user->setEmail($email);
                    $updatedFields[] = 'email';
                }
            }

            // Gestion avatar
            $avatarFile = $request->files->get('avatar');
            if ($avatarFile) {
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
                    $errors['avatar'] = 'Format d\'image non supporté. Formats acceptés : JPEG, PNG, GIF, WebP, BMP, TIFF, SVG';
                }

                // Sécurité SVG
                if ($mimeType === 'image/svg+xml') {
                    $svgContent = file_get_contents($avatarFile->getPathname());
                    if (preg_match('/<script/i', $svgContent)) {
                        $errors['avatar'] = 'Les SVG contenant des scripts ne sont pas autorisés';
                    }
                }

                if (!isset($errors['avatar'])) {
                    if (!is_dir($this->uploadDirectory)) {
                        mkdir($this->uploadDirectory, 0775, true);
                    }

                    $newFilename = $slugger->slug($user->getUsername())
                        . '-' . uniqid() . '.' . $avatarFile->guessExtension();

                    try {
                        // Déplacement nouvelle image
                        $avatarFile->move($this->uploadDirectory, $newFilename);

                        // Suppression ancienne image
                        $oldPhoto = $user->getPhoto();
                        if ($oldPhoto && !str_contains($oldPhoto, 'default')) {
                            $oldFilename = basename($oldPhoto);
                            $oldFilePath = $this->uploadDirectory . '/' . $oldFilename;

                            if (file_exists($oldFilePath) && is_writable($oldFilePath)) {
                                unlink($oldFilePath);
                            }
                        }

                        // Mise à jour chemin
                        $user->setPhoto('/users/img/' . $newFilename);
                        $updatedFields[] = 'avatar';
                    } catch (FileException $e) {
                        $errors['avatar'] = 'Erreur lors de l\'enregistrement de l\'image';
                    }
                }
            }

            // Mise à jour mot de passe
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
                return new JsonResponse([
                    'status' => 'error',
                    'message' => 'Des erreurs de validation sont survenues',
                    'errors' => $errors
                ], 400);
            }

            $entityManager->flush();

            return new JsonResponse([
                'status' => 'success',
                'message' => 'Mises à jour effectuées avec succès',
                'photo' => $user->getPhoto(),
                'updatedFields' => array_unique($updatedFields)
            ]);
        } catch (\Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => $errors ?? []
            ], $e->getCode() ?: 400);
        }
    }
}
