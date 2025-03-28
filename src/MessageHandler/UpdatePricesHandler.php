<?php

namespace App\MessageHandler;

use App\Entity\Product;
use App\Entity\ProductPrice;
use App\Message\UpdatePricesMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Psr\Log\LoggerInterface;

#[AsMessageHandler]
class UpdatePricesHandler
{
    private EntityManagerInterface $entityManager;
    private LoggerInterface $logger;

    public function __construct(EntityManagerInterface $entityManager, LoggerInterface $logger)
    {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
    }

    public function __invoke(UpdatePricesMessage $message): void
    {
        $this->logger->info("Mise à jour des prix lancée à " . $message->getTimestamp()->format('Y-m-d H:i:s'));

        $products = $this->entityManager->getRepository(Product::class)->findAll();

        if (!$products) {
            $this->logger->warning("Aucun produit trouvé pour mise à jour.");
            return;
        }

        foreach ($products as $product) {
            $this->updateProductPrice($product);
        }

        $this->entityManager->flush();
        $this->logger->info("Mise à jour des prix terminée.");
    }

    private function updateProductPrice(Product $product): void
    {
        $lastPrices = $this->entityManager->getRepository(ProductPrice::class)
            ->findBy(['product' => $product], ['createdAt' => 'DESC'], 5);

        if (empty($lastPrices)) {
            $this->logger->warning("Aucun prix trouvé pour le produit {$product->getId()}");
            return;
        }

        $currentPrice = $lastPrices[0]->getPrice();
        $newPrice = $this->calculateNewPrice($currentPrice, $lastPrices);
        
        $this->createNewPriceEntry($product, $currentPrice, $newPrice);
    }

    private function calculateNewPrice(float $currentPrice, array $lastPrices): float
    {
        $maxVariationPerUpdate = 0.05;
        $trend = $this->calculateTrend($lastPrices);
        
        $variation = $trend * mt_rand(90, 110) / 100 * $maxVariationPerUpdate;
        $newPrice = $currentPrice * (1 + $variation);
        
        $minPrice = $currentPrice * (1 - $maxVariationPerUpdate);
        $maxPrice = $currentPrice * (1 + $maxVariationPerUpdate);
        
        return round(max($minPrice, min($maxPrice, $newPrice)), 2);
    }

    private function calculateTrend(array $lastPrices): float
    {
        if (count($lastPrices) < 2) {
            return 0;
        }

        $totalVariation = 0;
        for ($i = 1; $i < count($lastPrices); $i++) {
            $previous = $lastPrices[$i]->getPrice();
            $current = $lastPrices[$i-1]->getPrice();
            $totalVariation += ($current - $previous) / $previous;
        }
        
        return $totalVariation / (count($lastPrices) - 1);
    }

    private function createNewPriceEntry(Product $product, float $currentPrice, float $newPrice): void
    {
        // Correction : Ajout de la parenthèse manquante
        $this->logger->info(sprintf(
            "Produit %d : %.2f → %.2f (Δ%.2f%%)",
            $product->getId(),
            $currentPrice,
            $newPrice,
            (($newPrice - $currentPrice) / $currentPrice * 100)
        )); // ← Parenthèse ajoutée ici

        $priceEntry = (new ProductPrice())
            ->setProduct($product)
            ->setPrice($newPrice)
            ->setTimestamp(new \DateTimeImmutable());

        $this->entityManager->persist($priceEntry);
    }
}