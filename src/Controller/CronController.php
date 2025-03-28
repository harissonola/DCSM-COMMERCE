<?php
// src/Controller/CronController.php

namespace App\Controller;

use App\Message\UpdatePricesMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

class CronController extends AbstractController
{
    #[Route('/cron/update-prices/{secret}', name: 'cron_update_prices', methods: ['GET'])]
    public function updatePrices(
        MessageBusInterface $messageBus,
        string $secret
    ): JsonResponse {
        // Vérification du secret
        if ($secret !== $_ENV['CRON_SECRET']) {
            return new JsonResponse(['status' => 'Unauthorized'], 401);
        }

        // Déclenchement de la mise à jour
        $messageBus->dispatch(new UpdatePricesMessage());

        return new JsonResponse(['status' => 'OK']);
    }
}