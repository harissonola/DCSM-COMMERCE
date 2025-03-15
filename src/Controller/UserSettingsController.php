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
    public function update(
        Request $request, 
        EntityManagerInterface $entityManager, 
        UserPasswordHasherInterface $passwordHasher, 
        SluggerInterface $slugger
    ): Response {
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

        // 📌 Chemin du répertoire des images sur le serveur FTP
        $ftpDirectory = "/htdocs/dcsm-commerce/users/img/";

        // Mise à jour de la photo de profil
        $avatarFile = $request->files->get('avatarUpload');
        if ($avatarFile) {
            // Suppression de l'ancienne image si elle existe
            if ($user->getPhoto()) {
                $oldAvatarPath = $ftpDirectory . $user->getPhoto();
                if (file_exists($oldAvatarPath)) {
                    unlink($oldAvatarPath);
                }
            }

            // Génération d'un nom unique pour la nouvelle image
            $newFilename = $slugger->slug($user->getUsername()) . '-' . uniqid() . '.' . $avatarFile->guessExtension();

            // Paramètres FTP
            $ftpServer   = "ftpupload.net";
            $ftpUsername = "if0_34880738";
            $ftpPassword = "WODanielH2006";

            // Connexion FTP avec timeout de 120 secondes
            $ftpConnection = ftp_connect($ftpServer, 21, 120);
            if (!$ftpConnection) {
                $this->addFlash('danger', 'Impossible de se connecter au serveur FTP.');
                return $this->redirectToRoute('app_user_settings');
            }
            $loginResult = ftp_login($ftpConnection, $ftpUsername, $ftpPassword);
            if (!$loginResult) {
                $this->addFlash('danger', 'Échec de la connexion FTP.');
                ftp_close($ftpConnection);
                return $this->redirectToRoute('app_user_settings');
            }
            ftp_set_option($ftpConnection, FTP_TIMEOUT_SEC, 120);

            // Activer le mode passif (pour éviter les problèmes de ports dynamiques)
            ftp_pasv($ftpConnection, true);

            // Créer récursivement le répertoire s'il n'existe pas
            ftpMkdirRecursive($ftpConnection, $ftpDirectory);
            ftp_chdir($ftpConnection, $ftpDirectory);

            // Créer un fichier temporaire pour l'upload
            $tempFilePath = '/tmp/' . $newFilename;
            // Déplacer le fichier téléchargé dans /tmp
            try {
                $avatarFile->move('/tmp', $newFilename);
            } catch (FileException $e) {
                $this->addFlash('danger', 'Erreur lors du déplacement de l\'image.');
                ftp_close($ftpConnection);
                return $this->redirectToRoute('app_user_settings');
            }

            // Vérifier que le fichier temporaire existe
            if (!file_exists($tempFilePath)) {
                $this->addFlash('danger', 'Le fichier n\'a pas été déplacé correctement vers le répertoire temporaire.');
                ftp_close($ftpConnection);
                return $this->redirectToRoute('app_user_settings');
            }

            // Uploader le fichier sur le serveur FTP en mode binaire
            $uploadResult = ftp_put($ftpConnection, $newFilename, $tempFilePath, FTP_BINARY);
            if (!$uploadResult) {
                $this->addFlash('danger', 'Erreur lors de l\'upload de l\'image. Veuillez réessayer.');
                ftp_close($ftpConnection);
                return $this->redirectToRoute('app_user_settings');
            }
            ftp_close($ftpConnection);
            unlink($tempFilePath);

            // Mettre à jour le nom de la photo dans l'utilisateur
            $user->setPhoto($newFilename);
        }

        // Mise à jour du mot de passe
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

        // Mise à jour des préférences de notifications par e-mail
        $emailNotifications = $request->request->has('emailNotifications');
        $user->setEmailNotifications($emailNotifications);

        // Enregistrer les modifications
        $entityManager->persist($user);
        $entityManager->flush();

        $this->addFlash('success', 'Vos informations ont été mises à jour avec succès.');
        return $this->redirectToRoute('app_user_settings');
    }
}

// Fonction utilitaire pour créer récursivement un répertoire FTP
function ftpMkdirRecursive($ftpConnection, string $directory): void {
    $directory = ltrim($directory, '/');
    $parts = explode('/', $directory);
    $path = '';
    foreach ($parts as $part) {
        $path .= '/' . $part;
        if (!@ftp_chdir($ftpConnection, $path)) {
            if (!ftp_mkdir($ftpConnection, $path)) {
                throw new \Exception("Impossible de créer le répertoire FTP : $path");
            }
        }
    }
}