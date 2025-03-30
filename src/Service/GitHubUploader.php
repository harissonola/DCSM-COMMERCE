<?php
namespace App\Service;

use Github\Client;
use Github\Exception\RuntimeException;

class GitHubUploader
{
    private Client $client;
    private string $repoOwner;
    private string $repoName;

    public function __construct(string $token, string $repoOwner, string $repoName)
    {
        if (empty($token) || empty($repoOwner) || empty($repoName)) {
            throw new \InvalidArgumentException("Le token, le propriétaire et le nom du dépôt GitHub sont requis.");
        }

        $this->client = new Client();
        // Ajout manuel du header d'authentification
        $this->client->addHeader('Authorization', 'token ' . $token);
        $this->repoOwner = $repoOwner;
        $this->repoName = $repoName;
    }

    public function uploadFile(string $fileContent, string $filePath, string $commitMessage = 'Upload file'): string
    {
        try {
            $filePath = ltrim($filePath, '/'); // Assurer un chemin correct

            /** @var \Github\Api\Repository\Contents $contentsApi */
            $contentsApi = $this->client->repo()->contents();

            // Vérifie si le fichier existe déjà
            try {
                $existingFile = $contentsApi->show(
                    $this->repoOwner,
                    $this->repoName,
                    $filePath,
                    'main'
                );

                // Mise à jour du fichier existant
                $response = $contentsApi->update(
                    $this->repoOwner,
                    $this->repoName,
                    $filePath,
                    base64_encode($fileContent),
                    $commitMessage,
                    $existingFile['sha'],
                    'main'
                );
            } catch (RuntimeException $e) {
                // Création du fichier s'il n'existe pas
                $response = $contentsApi->create(
                    $this->repoOwner,
                    $this->repoName,
                    $filePath,
                    base64_encode($fileContent),
                    $commitMessage,
                    'main'
                );
            }

            return $this->generateCdnUrl($filePath);
        } catch (RuntimeException $e) {
            throw new \Exception("Erreur lors de l'upload GitHub : " . $e->getMessage());
        }
    }

    private function generateCdnUrl(string $filePath): string
    {
        return sprintf(
            'https://raw.githubusercontent.com/%s/%s/main/%s',
            $this->repoOwner,
            $this->repoName,
            ltrim($filePath, '/')
        );
    }
}