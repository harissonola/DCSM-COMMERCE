<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Github\Client;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class UserSettingsController extends AbstractController
{
    private Client $githubClient;
    private Filesystem $filesystem;

    public function __construct(Client $githubClient, Filesystem $filesystem)
    {
        $this->githubClient = $githubClient;
        $this->filesystem = $filesystem;
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
            // $this->handleUsernameUpdate($request, $user, $errors, $updatedFields);
            $this->handleEmailUpdate($request, $user, $errors, $updatedFields);
            $this->handleAvatarUpload($request, $user, $errors, $updatedFields);
            $this->handlePasswordUpdate($request, $user, $passwordHasher, $errors, $updatedFields);
            $this->handleNotifications($request, $user, $updatedFields);
            $this->handleNameUpdate($request, $user, $errors, $updatedFields); // Gestion du prénom et nom

            // S'il y a des erreurs, lever une exception
            if (!empty($errors)) {
                throw new \Exception(implode(', ', $errors), 422);
            }

            // Enregistrement en base
            $entityManager->flush();

            // Réponse en cas de succès
            return $this->handleSuccessResponse($request, 'Mises à jour effectuées avec succès');
        } catch (\Exception $e) {
            // Réponse en cas d'erreur
            return $this->handleErrorResponse($request, $e);
        }
    }

    // private function handleUsernameUpdate(Request $request, User $user, array &$errors, array &$updatedFields): void
    // {
    //     if ($request->request->has('username')) {
    //         $username = $request->request->get('username');
    //         if (empty($username)) {
    //             $errors[] = 'Le nom d\'utilisateur ne peut pas être vide';
    //         } else {
    //             $user->setUsername($username);
    //             $updatedFields[] = 'username';
    //         }
    //     }
    // }

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

    /**
     * Mise à jour de l'avatar en utilisant la logique d'upload sur GitHub.
     */
    private function handleAvatarUpload(Request $request, User $user, array &$errors, array &$updatedFields): void
    {
        if ($request->files->has('imageFile')) {
            /** @var UploadedFile $file */
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

            try {
                // Préparation du répertoire temporaire
                $tempDir = $this->getParameter('kernel.project_dir') . '/var/tmp/';
                if (!$this->filesystem->exists($tempDir)) {
                    $this->filesystem->mkdir($tempDir, 0755);
                }

                $fileName = uniqid() . '.' . $file->guessExtension();
                $tempFilePath = $tempDir . $fileName;

                // Déplacement du fichier vers le répertoire temporaire
                $file->move($tempDir, $fileName);
                $fileContent = file_get_contents($tempFilePath);

                // Construction du chemin sur GitHub
                $githubPath = "users/img/{$fileName}";
                $cdnUrl = $this->uploadToGitHub($githubPath, $fileContent, 'Upload avatar utilisateur');

                // Suppression du fichier temporaire
                $this->filesystem->remove($tempFilePath);

                // Mise à jour de l'avatar de l'utilisateur
                $user->setPhoto($cdnUrl);
                $updatedFields[] = 'photo';
            } catch (\Exception $e) {
                $errors[] = "Erreur lors de l'upload de l'avatar : " . $e->getMessage();
                $user->setPhoto($this->getDefaultProfileImage());
            }
        }
    }

    private function handlePasswordUpdate(
        Request $request,
        User $user,
        UserPasswordHasherInterface $passwordHasher,
        array &$errors,
        array &$updatedFields
    ): void {
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

    private function handleSuccessResponse(Request $request, string $message): Response
    {
        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success' => true,
                'message' => $message
            ]);
        }

        $this->addFlash('success', $message);
        return $this->redirectToRoute('app_user_settings');
    }

    private function handleErrorResponse(Request $request, \Exception $exception): Response
    {
        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'success' => false,
                'message' => $exception->getMessage()
            ], ($exception->getCode() > 0 ? $exception->getCode() : 400));
        }

        $this->addFlash('error', $exception->getMessage());
        return $this->redirectToRoute('app_user_settings');
    }

    /**
     * Méthode pour uploader un fichier sur GitHub via l'API Git Data.
     */
    private function uploadToGitHub(string $filePath, string $content, string $message): string
    {
        $repoOwner = 'harissonola';
        $repoName = 'my-cdn';
        $branch = 'main';

        try {
            // Authentification via le token GitHub
            $this->githubClient->authenticate("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", null, Client::AUTH_ACCESS_TOKEN);

            // Récupérer la référence de la branche
            $reference = $this->githubClient->api('git')->references()->show($repoOwner, $repoName, 'heads/' . $branch);
            $currentCommitSha = $reference['object']['sha'];

            // Récupérer l'arbre du commit courant
            $commit = $this->githubClient->api('git')->commits()->show($repoOwner, $repoName, $currentCommitSha);
            $treeSha = $commit['tree']['sha'];

            // Création d'un blob avec le contenu encodé en base64
            $blob = $this->githubClient->api('git')->blobs()->create($repoOwner, $repoName, [
                'content' => base64_encode($content),
                'encoding' => 'base64'
            ]);

            // Création d'un nouvel arbre incluant le blob
            $tree = $this->githubClient->api('git')->trees()->create($repoOwner, $repoName, [
                'base_tree' => $treeSha,
                'tree' => [
                    [
                        'path' => $filePath,
                        'mode' => '100644',
                        'type' => 'blob',
                        'sha' => $blob['sha']
                    ]
                ]
            ]);

            // Création d'un nouveau commit
            $newCommit = $this->githubClient->api('git')->commits()->create($repoOwner, $repoName, [
                'message' => $message,
                'tree' => $tree['sha'],
                'parents' => [$currentCommitSha]
            ]);

            // Mise à jour de la référence de la branche
            $this->githubClient->api('git')->references()->update($repoOwner, $repoName, 'heads/' . $branch, [
                'sha' => $newCommit['sha']
            ]);

            // Retourne l'URL raw du fichier sur GitHub
            return "https://raw.githubusercontent.com/{$repoOwner}/{$repoName}/{$branch}/{$filePath}";
        } catch (\Exception $e) {
            throw new \Exception("Échec de l'upload sur GitHub : " . $e->getMessage());
        }
    }

    /**
     * Retourne l'URL d'une image par défaut déjà présente sur GitHub.
     */
    private function getDefaultProfileImage(): string
    {
        $defaultNumber = rand(1, 7);
        return "https://raw.githubusercontent.com/harissonola/my-cdn/main/users/img/default{$defaultNumber}.jpg";
    }
}