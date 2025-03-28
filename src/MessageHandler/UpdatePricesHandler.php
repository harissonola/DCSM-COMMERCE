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

    public function __invoke(UpdatePricesMessage $message)
    {
        $this->logger->info("Mise à jour des prix lancée à " . $message->getTimestamp()->format('Y-m-d H:i:s'));

        $products = $this->entityManager->getRepository(Product::class)->findAll();

        if (!$products) {
            $this->logger->warning("Aucun produit trouvé pour mise à jour.");
            return;
        }

        foreach ($products as $product) {
            $lastPrices = $this->entityManager->getRepository(ProductPrice::class)
                ->findBy(['product' => $product], ['createdAt' => 'DESC'], 5); // Récupère les 5 derniers prix

            if (empty($lastPrices)) {
                $this->logger->warning("Aucun prix trouvé pour le produit {$product->getId()}");
                continue;
            }

            $currentPrice = $lastPrices[0]->getPrice();
            $newPrice = $this->calculateNewPrice($currentPrice, $lastPrices);
            
            $this->generateNewPrice($product, $newPrice);
        }

        $this->entityManager->flush();
        $this->logger->info("Mise à jour des prix terminée.");
    }

    private function calculateNewPrice(float $currentPrice, array $lastPrices): float
    {
        // Paramètres de variation
        $maxDailyVariation = 0.20; // 20% de variation max par jour
        $maxVariationPerUpdate = $maxDailyVariation / (24 * 20); // 20 updates par heure * 24h = 480 updates par jour
        
        // Calcul de la tendance basée sur les derniers prix
        $trend = $this->calculateTrend($lastPrices);
        
        // Variation aléatoire mais limitée et influencée par la tendance
        $variation = $trend * mt_rand(80, 120) / 100 * $maxVariationPerUpdate;
        
        // Calcul du nouveau prix
        $newPrice = $currentPrice * (1 + $variation);
        
        // On s'assure que le prix ne descend pas en dessous d'un minimum
        $minPrice = $currentPrice * 0.90; // Pas plus de 10% de baisse en une fois
        $maxPrice = $currentPrice * 1.10; // Pas plus de 10% de hausse en une fois
        
        return max($minPrice, min($maxPrice, $newPrice));
    }

    private function calculateTrend(array $lastPrices): float
    {
        if (count($lastPrices) < 2) {
            return 0;
        }
        
        $sum = 0;
        $count = 0;
        
        for ($i = 1; $i < count($lastPrices); $i++) {
            $previousPrice = $lastPrices[$i]->getPrice();
            $currentPrice = $lastPrices[$i-1]->getPrice();
            $sum += ($currentPrice - $previousPrice) / $previousPrice;
            $count++;
        }
        
        return $sum / $count;
    }

    private function generateNewPrice(Product $product, float $newPrice): void
    {
        $newPrice = round($newPrice, 2);
        $currentPrice = $this->entityManager->getRepository(ProductPrice::class)
            ->findOneBy(['product' => $product], ['createdAt' => 'DESC'])->getPrice();

        $this->logger->info(sprintf(
            "Produit %d : Ancien prix %.2f → Nouveau prix %.2f (Variation: %.2f%%)",
            $product->getId(),
            $currentPrice,
            $newPrice,
            (($newPrice - $currentPrice) / $currentPrice) * 100
        ));

        $productPrice = new ProductPrice();
        $productPrice->setProduct($product);
        $productPrice->setPrice($newPrice);
        $productPrice->setTimestamp(new \DateTimeImmutable());

        $this->entityManager->persist($productPrice);
    }
}