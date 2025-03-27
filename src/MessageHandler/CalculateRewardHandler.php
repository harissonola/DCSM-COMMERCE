<?php
// src/MessageHandler/CalculateRewardHandler.php

namespace App\MessageHandler;

use App\Message\CalculateRewardMessage;
use App\Entity\User;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class CalculateRewardHandler
{
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function __invoke(CalculateRewardMessage $message): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($message->getUserId());
        if (!$user) {
            return; // L'utilisateur n'existe pas
        }

        $totalReward = 0;

        // Calcul des intérêts quotidiens basés sur les produits achetés
        foreach ($user->getProducts() as $product) {
            $totalReward += $product->getPrice() * ($user->getReferralRewardRate() / 100);
        }

        // Ajout des récompenses au solde
        $user->setBalance($user->getBalance() + $totalReward);

        // Bonus de 10$ pour 40 parrainages
        if ($user->getReward() > 0) {
            $user->setBalance($user->getBalance() + $user->getReward());
            $user->setReward(0); // Réinitialiser le bonus après versement
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}