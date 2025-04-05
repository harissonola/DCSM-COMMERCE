<?php

namespace App\MessageHandler;

use App\Message\CalculateRewardMessage;
use App\Entity\User;
use App\Entity\Product;
use App\Entity\ProductPrice;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use NumberFormatter;
use Psr\Log\LoggerInterface;

#[AsMessageHandler]
class CalculateRewardHandler
{
    private $entityManager;
    private $numberFormatter;
    private $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        NumberFormatter $numberFormatter,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->numberFormatter = $numberFormatter;
        $this->logger = $logger;
    }

    public function __invoke(CalculateRewardMessage $message): void
    {
        $user = $this->entityManager->getRepository(User::class)->find($message->getUserId());
        if (!$user) {
            $this->logger->warning('Utilisateur non trouvé', ['user_id' => $message->getUserId()]);
            return;
        }

        // Vérifier si l'utilisateur a déjà été rémunéré dans les dernières 24 heures
        $lastMiningTime = $user->getLastMiningTime();
        $now = new \DateTime();
        $twentyFourHoursAgo = (new \DateTime())->modify('-24 hours');

        // Si la dernière rémunération est plus récente que 24 heures, on ne fait rien
        if ($lastMiningTime && $lastMiningTime > $twentyFourHoursAgo) {
            $this->logger->info('Utilisateur déjà rémunéré dans les dernières 24h', [
                'user_id' => $user->getId(),
                'last_mining_time' => $lastMiningTime->format('Y-m-d H:i:s'),
                'next_eligible_time' => (clone $lastMiningTime)->modify('+24 hours')->format('Y-m-d H:i:s')
            ]);
            return;
        }

        $totalReward = 0;

        // Calcul des récompenses basées sur les produits achetés
        foreach ($user->getProduct() as $product) {
            // Récupérer le dernier prix du produit (prix actuel)
            $latestPrice = $this->entityManager->getRepository(ProductPrice::class)
                ->findOneBy(['product' => $product], ['timestamp' => 'DESC']);
                
            if (!$latestPrice) {
                $this->logger->warning('Aucun prix trouvé pour le produit', ['product_id' => $product->getId()]);
                continue;
            }
            
            $priceValue = $latestPrice->getPrice();
            
            // Formater le prix en USD pour affichage
            $formattedPrice = $this->numberFormatter->formatCurrency($priceValue, 'USD');
            $priceUSD = $formattedPrice ? (float) str_replace(['$', ','], ['', ''], $formattedPrice) : 0.0;

            // Calculer la récompense basée sur le taux défini pour l'utilisateur
            $productReward = $priceUSD * ($user->getReferralRewardRate() / 100);
            $totalReward += $productReward;
            
            $this->logger->info('Récompense calculée pour le produit', [
                'product_id' => $product->getId(),
                'price_original' => $priceValue,
                'price_usd' => $priceUSD,
                'reward_rate' => $user->getReferralRewardRate(),
                'product_reward' => $productReward
            ]);
        }

        if ($totalReward > 0) {
            // Ajouter les récompenses au solde de l'utilisateur
            $newBalance = $user->getBalance() + $totalReward;
            $user->setBalance($newBalance);
            
            // Mettre à jour la date de dernière rémunération
            $user->setLastMiningTime($now);
            
            $this->logger->info('Solde utilisateur mis à jour', [
                'user_id' => $user->getId(),
                'added_reward' => $totalReward,
                'new_balance' => $newBalance,
                'next_mining_time' => $now->format('Y-m-d H:i:s')
            ]);
        }

        // Traiter le bonus de parrainage si applicable
        if ($user->getReward() > 0) {
            $bonusAmount = $user->getReward();
            $user->setBalance($user->getBalance() + $bonusAmount);
            $user->setReward(0); // Réinitialiser le bonus après versement
            
            $this->logger->info('Bonus de parrainage ajouté', [
                'user_id' => $user->getId(),
                'bonus_amount' => $bonusAmount
            ]);
        }

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}