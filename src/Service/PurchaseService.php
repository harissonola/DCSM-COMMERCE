<?php

namespace App\Service;

use App\Entity\User;
use App\Message\CalculateRewardMessage;
use Symfony\Component\Messenger\MessageBusInterface;

class PurchaseService
{
    private $messageBus;

    public function __construct(MessageBusInterface $messageBus)
    {
        $this->messageBus = $messageBus;
    }

    public function onProductPurchase(User $user): void
    {
        // Envoyer un message pour calculer les récompenses
        $this->messageBus->dispatch(new CalculateRewardMessage($user->getId()));
    }
}