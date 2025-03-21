<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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

            // Mise à jour des informations de profil
            if ($request->request->has('username') && $request->request->has('email')) {
                $user->setUsername($request->request->get('username'));
                $user->setEmail($request->request->get('email'));
            }

            // Gestion de l'avatar
            $avatarFile = $request->files->get('avatarUpload');
            if ($avatarFile) {
                if (!is_dir($this->uploadDirectory)) {
                    mkdir($this->uploadDirectory, 0775, true);
                }

                $newFilename = $slugger->slug($user->getUsername()).'-'.uniqid().'.'.$avatarFile->guessExtension();
                $avatarFile->move($this->uploadDirectory, $newFilename);
                $user->setPhoto($newFilename);
            }

            // Mise à jour du mot de passe
            if ($request->request->has('currentPassword')) {
                $currentPassword = $request->request->get('currentPassword');
                $newPassword = $request->request->get('newPassword');
                $confirmPassword = $request->request->get('confirmPassword');

                if (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                    throw new \Exception('Le mot de passe actuel est incorrect.');
                }

                if ($newPassword !== $confirmPassword) {
                    throw new \Exception('Les nouveaux mots de passe ne correspondent pas.');
                }

                $user->setPassword($passwordHasher->hashPassword($user, $newPassword));
            }

            // Notifications
            $user->setEmailNotifications(
                $request->request->has('emailNotifications')
            );

            $entityManager->flush();

            return new JsonResponse([
                'status' => 'success',
                'message' => 'Mises à jour effectuées avec succès',
                'photo' => $user->getPhoto()
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 400);
        }
    }
}