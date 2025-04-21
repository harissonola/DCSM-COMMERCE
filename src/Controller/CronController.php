<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\ProductPrice;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;

class CronController extends AbstractController
{
    private EntityManagerInterface $em;
    private LoggerInterface $logger;
    private const EXCHANGE_RATE = 601.50;

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger)
    {
        $this->em     = $em;
        $this->logger = $logger;
    }

    #[Route('/cron/update-prices/{secret}', name: 'cron_update_prices', methods: ['GET'])]
    public function updatePrices(Request $request, string $secret): JsonResponse
    {
        // Vérification du secret
        if ($secret !== $_ENV['CRON_SECRET']) {
            $this->logger->warning('Tentative d\'accès non autorisée');
            return new JsonResponse(['status' => 'Unauthorized'], 401);
        }

        try {
            // 1️⃣ Mise à jour des prix des produits
            $this->logger->info('Début mise à jour des prix');
            $allProducts = $this->em->getRepository(Product::class)->findAll();
            $updatedPrices = [];

            if (empty($allProducts)) {
                return new JsonResponse(['status' => 'Aucun produit trouvé']);
            }

            foreach ($allProducts as $product) {
                $newPrice = $this->processProduct($product);
                $updatedPrices[] = [
                    'product_id' => $product->getId(),
                    'new_price'  => $newPrice,
                    'updated_at' => (new \DateTime())->format('Y-m-d H:i:s'),
                ];
            }

            // Persist des nouveaux prix produits
            $this->em->flush();

            // 2️⃣ Calcul des récompenses utilisateurs
            $now = new \DateTimeImmutable();
            $users = $this->em->getRepository(User::class)
                ->createQueryBuilder('u')
                ->innerJoin('u.product', 'p')
                ->distinct()
                ->getQuery()
                ->getResult();

            $userRewards = [];
            foreach ($users as $user) {
                // Vérifier intervalle de 24h depuis dernière récompense
                $lastRewardAt = $user->getLastReferralRewardAt();
                if ($lastRewardAt instanceof \DateTimeImmutable &&
                    ($now->getTimestamp() - $lastRewardAt->getTimestamp()) < 86400) {
                    continue;
                }

                $userProducts = $user->getProduct();
                if ($userProducts->isEmpty()) {
                    continue;
                }

                $totalReward = 0.0;
                foreach ($userProducts as $product) {
                    // Conversion du prix en USD
                    $priceUsd = $product->getPrice() / self::EXCHANGE_RATE;
                    $rate     = $user->getReferralRewardRate(); // ex: 0.10 pour 10%
                    dd($priceUsd, $rate);
                    $totalReward += $priceUsd * $rate;
                }

                if ($totalReward <= 0) {
                    continue;
                }

                $oldBalance = $user->getBalance();
                $newBalance = $oldBalance + $totalReward;
                $user->setBalance($newBalance);
                $user->setLastReferralRewardAt($now);

                $userRewards[] = [
                    'user_id'       => $user->getId(),
                    'reward_amount' => round($totalReward, 2),
                    'old_balance'   => round($oldBalance, 2),
                    'new_balance'   => round($newBalance, 2),
                ];

                $this->logger->info(sprintf(
                    'User %d : récompense de %0.2f USD ajoutée (ancien solde: %0.2f, nouveau solde: %0.2f)',
                    $user->getId(),
                    $totalReward,
                    $oldBalance,
                    $newBalance
                ));

                $this->em->persist($user);
            }

            // Persist des mises à jour des soldes utilisateurs
            $this->em->flush();

            // 3️⃣ Réponse JSON
            return new JsonResponse([
                'status'         => 'success',
                'count'          => count($allProducts),
                'updated_prices' => $updatedPrices,
                'user_rewards'   => $userRewards,
                'execution_time' => (new \DateTime())->format('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur : ' . $e->getMessage());
            return new JsonResponse([
                'status'    => 'error',
                'message'   => $e->getMessage(),
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            ], 500);
        }
    }

    private function processProduct(Product $product): float
    {
        // Récupère les 5 dernières entrées pour plus de réactivité
        $prices = $this->em->getRepository(ProductPrice::class)
            ->findBy(['product' => $product], ['timestamp' => 'DESC'], 5);

        // Prix de base si pas d'historique
        $basePrice = $product->getPrice() ?? 100.00;

        if (empty($prices)) {
            $newPrice = $this->generateRandomPrice($basePrice, 0.30);
            $this->createPriceEntry($product, $newPrice);
            return $newPrice;
        }

        $currentPrice = $prices[0]->getPrice();
        $newPrice     = $this->calculateDynamicPrice($currentPrice, $prices);
        $this->createPriceEntry($product, $newPrice);

        return $newPrice;
    }

    private function calculateDynamicPrice(float $currentPrice, array $history): float
    {
        $maxDailyVariation = 0.10;
        $volatilityFactor  = 2.5;
        $momentumFactor    = 0.5;

        // Calcul de la tendance pondérée
        $trend = $this->calculateWeightedTrend($history);
        $variation = $trend * $volatilityFactor * ($maxDailyVariation / 24);

        if ($trend > 0) {
            $variation += $momentumFactor * ($maxDailyVariation / 24);
        } elseif ($trend < 0) {
            $variation -= $momentumFactor * ($maxDailyVariation / 24);
        }

        // Composante aléatoire
        $randomComponent = $this->getGaussianRandom(0, 3) * ($maxDailyVariation / 24);
        $variation += $randomComponent;

        // Pression baissière accrue
        if ($currentPrice < 20) {
            $variation *= 1.5;
            $variation = max($variation, -0.4);
        }

        // Limites
        $variation = max($variation, -0.50);
        $variation = min($variation, 0.50);

        $newPrice = max(round($currentPrice * (1 + $variation), 2), 0);
        if ($newPrice === $currentPrice) {
            $newPrice += ($variation > 0 ? 0.01 : -0.01);
        }

        return $newPrice;
    }

    private function calculateWeightedTrend(array $prices): float
    {
        if (count($prices) < 2) {
            return 0;
        }

        $total = 0;
        $weightSum = 0;
        $count = count($prices);
        for ($i = 1; $i < $count; $i++) {
            $weight = pow(3, $count - $i);
            $change = ($prices[$i-1]->getPrice() - $prices[$i]->getPrice()) / $prices[$i]->getPrice();
            $total     += $change * $weight;
            $weightSum += $weight;
        }

        return $weightSum ? $total / $weightSum : 0;
    }

    private function generateRandomPrice(float $basePrice, float $variationPercent): float
    {
        $variation = $this->getGaussianRandom(0, $variationPercent);
        return round($basePrice * (1 + $variation), 2);
    }

    private function createPriceEntry(Product $product, float $price): void
    {
        $price = max($price, 0);
        $entry = (new ProductPrice())
            ->setProduct($product)
            ->setPrice($price)
            ->setTimestamp(new \DateTimeImmutable());

        $product->setPrice($price);
        $this->em->persist($entry);
        $this->logger->info(sprintf('Produit %d : Nouvelle entrée de prix → %0.2f', $product->getId(), $price));
    }

    private function getGaussianRandom(float $mean = 0, float $stdDev = 1): float
    {
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();
        $z  = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
        return $mean + $z * $stdDev;
    }
}
