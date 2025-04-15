<?php

// src/Service/FtpService.php

namespace App\Service;

class FtpService
{
    public function ftpMkdirRecursive($ftpConnection, string $directory): void
    {
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
}
