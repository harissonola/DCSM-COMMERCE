<?php
namespace App\Service;

use Github\Client;
use Github\Exception\ExceptionInterface;

class GitHubUploader
{
    private $client;
    private $repoOwner;
    private $repoName;

    public function __construct(string $token, string $repoOwner, string $repoName)
    {
        if (empty($token)) {
            throw new \InvalidArgumentException("Le token GitHub est requis.");
        }
        if (empty($repoOwner) || empty($repoName)) {
            throw new \InvalidArgumentException("Le propriétaire et le nom du dépôt GitHub sont requis.");
        }

        $this->client = new Client();
        $this->client->authenticate($token, null, Client::AUTH_ACCESS_TOKEN);
        $this->repoOwner = $repoOwner;
        $this->repoName = $repoName;
    }

    public function uploadFile($fileContent, string $filePath, string $commitMessage = 'Upload file'): string
    {
        try {
            $this->ensureDirectoryExists(dirname($filePath));

            // Vérifie si le fichier existe
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
            } catch (ExceptionInterface $e) {
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
        } catch (ExceptionInterface $e) {
            throw new \Exception("Échec de l'upload GitHub : " . $e->getMessage());
        }
    }

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
                    "Création du répertoire {$part}",
                    'main'
                );
            } catch (ExceptionInterface $e) {
                continue;
            }
        }
    }

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