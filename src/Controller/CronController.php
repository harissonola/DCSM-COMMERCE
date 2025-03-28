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

            if (empty($products)) {
                return new JsonResponse(['status' => 'Aucun produit trouvé']);
            }

            foreach ($products as $product) {
                $this->processProduct($product);
            }

            $this->em->flush();
            return new JsonResponse([
                'status' => 'success',
                'count' => count($products)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Erreur : ' . $e->getMessage());
            return new JsonResponse(['status' => 'error'], 500);
        }
    }

    private function processProduct(Product $product): void
    {
        $prices = $this->em->getRepository(ProductPrice::class)
            ->findBy(
                ['product' => $product], 
                ['timestamp' => 'DESC'], // Correction ici
                5
            );

        if (empty($prices)) return;

        $currentPrice = $prices[0]->getPrice();
        $newPrice = $this->calculateNewPrice($currentPrice, $prices);
        
        $this->createPriceEntry($product, $newPrice);
    }

    private function calculateNewPrice(float $currentPrice, array $prices): float
    {
        $maxVariation = 0.05;
        $trend = $this->calculateTrend($prices);
        $variation = $trend * mt_rand(90, 110) / 100 * $maxVariation;
        
        $newPrice = $currentPrice * (1 + $variation);
        $boundedPrice = max(
            $currentPrice * 0.95, 
            min($currentPrice * 1.05, $newPrice)
        );
        
        return round($boundedPrice, 2);
    }

    private function calculateTrend(array $prices): float
    {
        if (count($prices) < 2) return 0;
        
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
        $this->logger->info("Produit {$product->getId()} : {$price}");
    }
}