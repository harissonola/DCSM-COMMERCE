<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\ProductPrice;
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

    public function __construct(EntityManagerInterface $em, LoggerInterface $logger)
    {
        $this->em = $em;
        $this->logger = $logger;
    }

    #[Route('/cron/update-prices/{secret}', name: 'cron_update_prices', methods: ['GET'])]
    public function updatePrices(Request $request, string $secret): JsonResponse
    {
        if ($secret !== $_ENV['CRON_SECRET']) {
            $this->logger->warning('Tentative d\'accès non autorisée');
            return new JsonResponse(['status' => 'Unauthorized'], 401);
        }

        try {
            $this->logger->info('Début mise à jour des prix');
            $products = $this->em->getRepository(Product::class)->findAll();
            $updatedPrices = [];

            if (empty($products)) {
                return new JsonResponse(['status' => 'Aucun produit trouvé']);
            }

            foreach ($products as $product) {
                $newPrice = $this->processProduct($product);
                $updatedPrices[] = [
                    'product_id' => $product->getId(),
                    'new_price' => $newPrice,
                    'updated_at' => (new \DateTime())->format('Y-m-d H:i:s')
                ];
            }

            $this->em->flush();
            
            return new JsonResponse([
                'status' => 'success',
                'count' => count($products),
                'updated_prices' => $updatedPrices,
                'execution_time' => (new \DateTime())->format('Y-m-d H:i:s')
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Erreur : ' . $e->getMessage());
            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
            ], 500);
        }
    }

    private function processProduct(Product $product): float
    {
        $prices = $this->em->getRepository(ProductPrice::class)
            ->findBy(['product' => $product], ['timestamp' => 'DESC'], 10); // Augmenté à 10 entrées

        $basePrice = $product->getPrice() ?? 100.00;
        
        if (empty($prices)) {
            $newPrice = $this->generateRandomPrice($basePrice, 0.15); // +-15% pour le premier prix
            $this->createPriceEntry($product, $newPrice);
            return $newPrice;
        }

        $currentPrice = $prices[0]->getPrice();
        $newPrice = $this->calculateDynamicPrice($currentPrice, $prices);
        $this->createPriceEntry($product, $newPrice);
        
        return $newPrice;
    }

    private function calculateDynamicPrice(float $currentPrice, array $priceHistory): float
    {
        // Paramètres ajustables
        $maxDailyVariation = 0.20; // 20% max par jour
        $minVariation = 0.01; // 1% minimum
        
        // Calcul de tendance pondérée
        $trend = $this->calculateWeightedTrend($priceHistory);
        
        // Variation aléatoire plus dynamique
        $randomFactor = mt_rand(80, 120) / 100;
        $variation = $trend * $randomFactor * ($maxDailyVariation / 48); // 48 updates par jour (toutes les 30 min)
        
        // Garantir une variation minimale
        $variation = abs($variation) < $minVariation 
            ? ($variation >= 0 ? $minVariation : -$minVariation)
            : $variation;
        
        $newPrice = $currentPrice * (1 + $variation);
        
        return round($newPrice, 2);
    }

    private function calculateWeightedTrend(array $prices): float
    {
        if (count($prices) < 2) return 0;

        $total = 0;
        $totalWeight = 0;
        
        for ($i = 1; $i < count($prices); $i++) {
            $weight = pow(1.5, $i); // Poids exponentiel
            $change = ($prices[$i-1]->getPrice() - $prices[$i]->getPrice()) / $prices[$i]->getPrice();
            $total += $change * $weight;
            $totalWeight += $weight;
        }
        
        return $total / $totalWeight;
    }

    private function generateRandomPrice(float $basePrice, float $variationPercent): float
    {
        $variation = mt_rand(-$variationPercent*100, $variationPercent*100) / 100;
        return round($basePrice * (1 + $variation), 2);
    }

    private function createPriceEntry(Product $product, float $price): void
    {
        $entry = (new ProductPrice())
            ->setProduct($product)
            ->setPrice($price)
            ->setTimestamp(new \DateTimeImmutable());

        $this->em->persist($entry);
        $this->logger->info(sprintf(
            'Produit %d - Ancien: %.2f | Nouveau: %.2f | Variation: %.2f%%',
            $product->getId(),
            $product->getPrice(),
            $price,
            (($price - $product->getPrice()) / $product->getPrice()) * 100
        ));
    }
}