<?php

namespace App\Service;

use Github\Client;
use Github\Exception\ErrorException;

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

    public function uploadFile(string $fileContent, string $filePath, string $commitMessage = 'Upload file'): string
    {
        try {
            $this->createParentDirectories(dirname($filePath));

            $this->client->api('repo')->contents()->create(
                $this->repoOwner,
                $this->repoName,
                $filePath,
                base64_encode($fileContent),
                $commitMessage,
                'main'
            );

            return $this->generateCdnUrl($filePath);
        } catch (ErrorException $e) {
            throw new \Exception("Échec de l'upload sur GitHub: " . $e->getMessage());
        }
    }

    private function createParentDirectories(string $path): void
    {
        $parts = array_filter(explode('/', $path));
        $currentPath = '';

        foreach ($parts as $part) {
            $currentPath .= "{$part}/";
            try {
                $this->client->api('repo')->contents()->create(
                    $this->repoOwner,
                    $this->repoName,
                    rtrim($currentPath, '/'),
                    '',
                    "Création du dossier {$part}",
                    'main'
                );
            } catch (ErrorException $e) {
                // Le dossier existe déjà
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