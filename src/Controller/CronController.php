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
                    'new_price' => $newPrice ?? $product->getPrice() // Garde le prix actuel si null
                ];
            }

            $this->em->flush();
            
            return new JsonResponse([
                'status' => 'success',
                'count' => count($products),
                'updated_prices' => $updatedPrices
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Erreur : ' . $e->getMessage());
            return new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    private function processProduct(Product $product): ?float
    {
        $prices = $this->em->getRepository(ProductPrice::class)
            ->findBy(['product' => $product], ['timestamp' => 'DESC'], 5);

        // Si pas d'historique, on crée un premier prix basé sur le prix du produit
        if (empty($prices)) {
            $basePrice = $product->getPrice() ?? 100.00; // Valeur par défaut si null
            $newPrice = $this->generateInitialPrice($basePrice);
            $this->createPriceEntry($product, $newPrice);
            return $newPrice;
        }

        $currentPrice = $prices[0]->getPrice();
        $newPrice = $this->calculateNewPrice($currentPrice, $prices);
        $this->createPriceEntry($product, $newPrice);
        
        return $newPrice;
    }

    private function generateInitialPrice(float $basePrice): float
    {
        // Variation aléatoire entre -10% et +10% pour le premier prix
        $variation = mt_rand(-10, 10) / 100;
        return round($basePrice * (1 + $variation), 2);
    }

    private function calculateNewPrice(float $currentPrice, array $prices): float
    {
        $maxVariation = 0.05;
        $trend = count($prices) > 1 ? $this->calculateTrend($prices) : 0;
        $variation = $trend * mt_rand(90, 110) / 100 * $maxVariation;
        
        $newPrice = $currentPrice * (1 + $variation);
        $boundedPrice = max($currentPrice * 0.95, min($currentPrice * 1.05, $newPrice));
        
        return round($boundedPrice, 2);
    }

    private function calculateTrend(array $prices): float
    {
        $total = 0;
        for ($i = 1; $i < count($prices); $i++) {
            $older = $prices[$i]->getPrice();
            $newer = $prices[$i-1]->getPrice();
            $total += ($newer - $older) / $older;
        }
        return $total / (count($prices) - 1);
    }

    private function createPriceEntry(Product $product, float $price): void
    {
        $entry = (new ProductPrice())
            ->setProduct($product)
            ->setPrice($price)
            ->setTimestamp(new \DateTimeImmutable());

        $this->em->persist($entry);
        $this->logger->info(sprintf(
            'Produit %d - Prix: %.2f',
            $product->getId(),
            $price
        ));
    }
}