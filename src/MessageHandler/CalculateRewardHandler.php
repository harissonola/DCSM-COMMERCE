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
        $now = new \DateTime();
        $twentyFourHoursAgo = (new \DateTime())->modify('-24 hours');
        
        $userRepository = $this->entityManager->getRepository(User::class);
        
        // Récupérer tous les utilisateurs qui possèdent au moins un produit
        $users = $userRepository->createQueryBuilder('u')
            ->join('u.product', 'p')
            ->groupBy('u.id')
            ->getQuery()
            ->getResult();
            
        $this->logger->info(sprintf('Traitement de %d utilisateurs possédant des produits', count($users)));

        // Récupérer tous les derniers prix des produits en une seule requête
        $latestPrices = [];
        $productRepository = $this->entityManager->getRepository(Product::class);
        $allProducts = $productRepository->findAll();
        
        foreach ($allProducts as $product) {
            $latestPrice = $this->entityManager->getRepository(ProductPrice::class)
                ->findOneBy(['product' => $product], ['timestamp' => 'DESC']);
            if ($latestPrice) {
                $latestPrices[$product->getId()] = $latestPrice;
            }
        }

        $batchSize = 20;
        $i = 0;
        $usersRewarded = 0;

        // Traiter chaque utilisateur ayant des produits
        foreach ($users as $user) {
            // Vérifier si l'utilisateur a déjà été rémunéré dans les dernières 24 heures
            $lastMiningTime = $user->getLastMiningTime();
            
            if ($lastMiningTime && $lastMiningTime > $twentyFourHoursAgo) {
                $this->logger->debug('Utilisateur déjà rémunéré dans les dernières 24h', [
                    'user_id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'last_mining_time' => $lastMiningTime->format('Y-m-d H:i:s')
                ]);
                continue;
            }

            $totalReward = 0;
            $productsProcessed = 0;

            // Calcul des récompenses basées sur les produits achetés
            foreach ($user->getProduct() as $product) {
                $productId = $product->getId();
                $productsProcessed++;
                
                // Utiliser le prix préchargé
                if (!isset($latestPrices[$productId])) {
                    $this->logger->warning('Aucun prix trouvé pour le produit', [
                        'product_id' => $productId,
                        'user_id' => $user->getId()
                    ]);
                    continue;
                }
                
                $latestPrice = $latestPrices[$productId];
                $priceValue = $latestPrice->getPrice();
                
                // Formater le prix en USD
                $formattedPrice = $this->numberFormatter->formatCurrency($priceValue, 'USD');
                $priceUSD = $formattedPrice ? (float) str_replace(['$', ','], ['', ''], $formattedPrice) : 0.0;

                // Calculer la récompense
                $productReward = $priceUSD * ($user->getReferralRewardRate() / 100);
                $totalReward += $productReward;
            }

            if ($totalReward > 0) {
                // Ajouter les récompenses au solde de l'utilisateur
                $newBalance = $user->getBalance() + $totalReward;
                $user->setBalance($newBalance);
                
                // Mettre à jour la date de dernière rémunération
                $user->setLastMiningTime($now);
                $usersRewarded++;
                
                $this->logger->info('Solde utilisateur mis à jour', [
                    'user_id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'products_count' => $productsProcessed,
                    'added_reward' => $totalReward,
                    'new_balance' => $newBalance
                ]);
            }

            // Traiter le bonus de parrainage si applicable
            if ($user->getReward() > 0) {
                $bonusAmount = $user->getReward();
                $user->setBalance($user->getBalance() + $bonusAmount);
                $user->setReward(0); // Réinitialiser le bonus après versement
                
                $this->logger->info('Bonus de parrainage ajouté', [
                    'user_id' => $user->getId(),
                    'username' => $user->getUsername(),
                    'bonus_amount' => $bonusAmount
                ]);
            }

            $this->entityManager->persist($user);
            
            // Flush périodiquement pour éviter de consommer trop de mémoire
            if (++$i % $batchSize === 0) {
                $this->entityManager->flush();
                $this->entityManager->clear(); // Libère la mémoire
                
                // Recharger l'EntityManager après un clear
                if ($i < count($users)) {
                    $userRepository = $this->entityManager->getRepository(User::class);
                }
                
                $this->logger->info(sprintf('Progression: %d/%d utilisateurs traités', $i, count($users)));
            }
        }
        
        // Flush final pour les derniers utilisateurs
        $this->entityManager->flush();
        $this->logger->info('Traitement terminé', [
            'total_users' => count($users),
            'users_rewarded' => $usersRewarded
        ]);
    }
}