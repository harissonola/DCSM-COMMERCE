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
        // Vérification du secret
        if ($secret !== $_ENV['CRON_SECRET']) {
            $this->logger->warning('Tentative d\'accès non autorisée à la route cron');
            return new JsonResponse(['status' => 'Unauthorized'], 401);
        }

        try {
            $this->logger->info('Début de la mise à jour des prix');
            
            $products = $this->em->getRepository(Product::class)->findAll();

            if (empty($products)) {
                $this->logger->warning('Aucun produit trouvé');
                return new JsonResponse(['status' => 'No products found']);
            }

            foreach ($products as $product) {
                $this->processProduct($product);
            }

            $this->em->flush();
            $this->logger->info('Mise à jour des prix terminée avec succès');

            return new JsonResponse([
                'status' => 'success',
                'products_updated' => count($products)
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la mise à jour : ' . $e->getMessage());
            return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function processProduct(Product $product): void
    {
        $lastPrices = $this->em->getRepository(ProductPrice::class)
            ->findBy(['product' => $product], ['createdAt' => 'DESC'], 5);

        if (empty($lastPrices)) {
            $this->logger->warning("Aucun historique pour le produit {$product->getId()}");
            return;
        }

        $currentPrice = $lastPrices[0]->getPrice();
        $newPrice = $this->calculateNewPrice($currentPrice, $lastPrices);

        $this->createPriceEntry($product, $newPrice);
    }

    private function calculateNewPrice(float $currentPrice, array $lastPrices): float
    {
        $maxVariation = 0.05; // 5% max
        $trend = $this->calculateTrend($lastPrices);
        $variation = $trend * mt_rand(90, 110) / 100 * $maxVariation;

        $newPrice = $currentPrice * (1 + $variation);
        $minPrice = $currentPrice * (1 - $maxVariation);
        $maxPrice = $currentPrice * (1 + $maxVariation);

        return round(max($minPrice, min($maxPrice, $newPrice)), 2);
    }

    private function calculateTrend(array $lastPrices): float
    {
        if (count($lastPrices) < 2) return 0;

        $total = 0;
        for ($i = 1; $i < count($lastPrices); $i++) {
            $prev = $lastPrices[$i]->getPrice();
            $current = $lastPrices[$i-1]->getPrice();
            $total += ($current - $prev) / $prev;
        }

        return $total / (count($lastPrices) - 1);
    }

    private function createPriceEntry(Product $product, float $price): void
    {
        $priceEntry = new ProductPrice();
        $priceEntry->setProduct($product)
            ->setPrice($price)
            ->setTimestamp(new \DateTimeImmutable());

        $this->em->persist($priceEntry);
        $this->logger->info(sprintf(
            'Produit %d - Nouveau prix : %.2f',
            $product->getId(),
            $price
        ));
    }
}