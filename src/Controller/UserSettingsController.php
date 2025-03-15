<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;

final class UserSettingsController extends AbstractController
{
    #[Route('/user/settings', name: 'app_user_settings')]
    public function index(): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }
        
        return $this->render('user_settings/index.html.twig', [
            'controller_name' => 'UserSettingsController',
        ]);
    }

    #[Route('/user/settings/update', name: 'app_user_settings_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $entityManager, UserPasswordHasherInterface $passwordHasher, SluggerInterface $slugger): Response
    {

        // Récupérer l'utilisateur connecté
        /** @var User $user */
        $user = $this->getUser();

        if (!$user) {
            $this->addFlash('danger', 'Utilisateur non authentifié.');
            return $this->redirectToRoute('app_login');
        }

        // Mise à jour des informations de profil
        if ($request->request->has('username') && $request->request->has('email')) {
            $user->setUsername($request->request->get('username'));
            $user->setEmail($request->request->get('email'));
        }

        // 📌 Chemin du répertoire des images des utilisateurs
        $uploadDir = $this->getParameter('users_img_directory');

        // ✅ Mise à jour de la photo de profil
        $avatarFile = $request->files->get('avatarUpload');
        if ($avatarFile) {
            // 🔥 Suppression de l'ancienne image s'il y en a une
            if ($user->getPhoto()) {
                $oldAvatarPath = $uploadDir . '/' . $user->getPhoto();
                if (file_exists($oldAvatarPath)) {
                    unlink($oldAvatarPath);
                }
            }

            // 📌 Génération d'un nom unique pour la nouvelle image
            $newFilename = $slugger->slug($user->getUsername()) . '-' . uniqid() . '.' . $avatarFile->guessExtension();

            try {
                $avatarFile->move($uploadDir, $newFilename);
                $user->setPhoto($newFilename);
            } catch (FileException $e) {
                $this->addFlash('danger', 'Erreur lors de l\'upload de l\'image. Veuillez réessayer.');
            }
        }

        // ✅ Mise à jour du mot de passe
        if ($request->request->has('currentPassword') && $request->request->has('newPassword') && $request->request->has('confirmPassword')) {
            $currentPassword = $request->request->get('currentPassword');
            $newPassword = $request->request->get('newPassword');
            $confirmPassword = $request->request->get('confirmPassword');

            if ($passwordHasher->isPasswordValid($user, $currentPassword)) {
                if ($newPassword === $confirmPassword) {
                    $hashedPassword = $passwordHasher->hashPassword($user, $newPassword);
                    $user->setPassword($hashedPassword);
                } else {
                    $this->addFlash('danger', 'Les nouveaux mots de passe ne correspondent pas.');
                }
            } else {
                $this->addFlash('danger', 'Le mot de passe actuel est incorrect.');
            }
        }

        // ✅ Mise à jour des préférences de notifications par e-mail
        $emailNotifications = $request->request->has('emailNotifications');
        $user->setEmailNotifications($emailNotifications);

        // ✅ Enregistrer les modifications dans la base de données
        $entityManager->persist($user);
        $entityManager->flush();

        $this->addFlash('success', 'Vos informations ont été mises à jour avec succès.');

        // ✅ Redirection après la mise à jour
        return $this->redirectToRoute('app_user_settings');
    }
}