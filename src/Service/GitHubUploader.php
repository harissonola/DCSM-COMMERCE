<?php

namespace App\Service;

use Github\Client;
use Github\Exception\ErrorException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class GitHubUploader
{
    private $client;
    private $repoOwner;
    private $repoName;

    public function __construct(string $token, string $repoOwner, string $repoName)
    {
        $this->client = new Client();
        $this->client->authenticate($token, null, Client::AUTH_ACCESS_TOKEN);
        $this->repoOwner = $repoOwner;
        $this->repoName = $repoName;
    }

    /**
     * Upload un fichier vers GitHub.
     */
    public function uploadFile($fileContent, string $filePath, string $commitMessage = 'Upload file'): string
    {
        try {
            $this->ensureDirectoryExists(dirname($filePath));

            // Vérifie si le fichier existe déjà
            try {
                $existingFile = $this->client->api('repo')->contents()->show(
                    $this->repoOwner,
                    $this->repoName,
                    $filePath,
                    'main'
                );

                // Mise à jour du fichier existant
                $response = $this->client->api('repo')->contents()->update(
                    $this->repoOwner,
                    $this->repoName,
                    $filePath,
                    base64_encode($fileContent),
                    $commitMessage,
                    $existingFile['sha'],
                    'main'
                );
            } catch (ErrorException $e) {
                // Création du fichier s'il n'existe pas
                $response = $this->client->api('repo')->contents()->create(
                    $this->repoOwner,
                    $this->repoName,
                    $filePath,
                    base64_encode($fileContent),
                    $commitMessage,
                    'main'
                );
            }

            return $this->generateCdnUrl($filePath);
        } catch (ErrorException $e) {
            throw new \Exception("GitHub upload failed: " . $e->getMessage());
        }
    }

    /**
     * Assure que les répertoires nécessaires existent.
     */
    private function ensureDirectoryExists(string $directoryPath): void
    {
        $parts = array_filter(explode('/', $directoryPath));
        $currentPath = '';

        foreach ($parts as $part) {
            $currentPath .= "{$part}/";

            try {
                $this->client->api('repo')->contents()->create(
                    $this->repoOwner,
                    $this->repoName,
                    rtrim($currentPath, '/'),
                    '',
                    "Create directory {$part}",
                    'main'
                );
            } catch (ErrorException $e) {
                // Le répertoire existe probablement déjà
                continue;
            }
        }
    }

    /**
     * Génère une URL CDN pour le fichier.
     */
    private function generateCdnUrl(string $filePath): string
    {
        return sprintf(
            'https://cdn.jsdelivr.net/gh/%s/%s@main/%s',
            $this->repoOwner,
            $this->repoName,
            ltrim($filePath, '/')
        );
    }
}