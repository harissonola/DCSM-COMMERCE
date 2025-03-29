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
        $this->em     = $em;
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
                    'product_id'  => $product->getId(),
                    'new_price'   => $newPrice,
                    'updated_at'  => (new \DateTime())->format('Y-m-d H:i:s')
                ];
            }

            // Flush global pour enregistrer toutes les modifications (mise à jour de Product et ProductPrice)
            $this->em->flush();
            
            return new JsonResponse([
                'status'         => 'success',
                'count'          => count($products),
                'updated_prices' => $updatedPrices,
                'execution_time' => (new \DateTime())->format('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur : ' . $e->getMessage());
            return new JsonResponse([
                'status'    => 'error',
                'message'   => $e->getMessage(),
                'timestamp' => (new \DateTime())->format('Y-m-d H:i:s')
            ], 500);
        }
    }

    private function processProduct(Product $product): float
    {
        // Récupère les 10 dernières entrées de ProductPrice pour ce produit
        $prices = $this->em->getRepository(ProductPrice::class)
            ->findBy(['product' => $product], ['timestamp' => 'DESC'], 10);

        // Pour la première mise à jour, on part du prix initial de l'entité Product
        $basePrice = $product->getPrice() ?? 100.00;
        
        if (empty($prices)) {
            // Applique une variation aléatoire pour amorcer le mouvement
            $newPrice = $this->generateRandomPrice($basePrice, 0.30);
            $this->createPriceEntry($product, $newPrice);
            return $newPrice;
        }

        // Ensuite, on se base sur le dernier prix enregistré dans ProductPrice (qui est mis à jour dans Product)
        $currentPrice = $prices[0]->getPrice();
        $newPrice = $this->calculateDynamicPrice($currentPrice, $prices);
        $this->createPriceEntry($product, $newPrice);
        
        return $newPrice;
    }

    private function calculateDynamicPrice(float $currentPrice, array $priceHistory): float
    {
        // Paramètres ajustables
        $maxDailyVariation = 0.40; // Variation maximale quotidienne
        $volatilityFactor  = 2.5;  // Amplificateur de volatilité

        // Calcul de la tendance pondérée basée sur l'historique
        $trend = $this->calculateWeightedTrend($priceHistory);
        // Génération d'un facteur aléatoire (distribution normale)
        $randomFactor = $this->getGaussianRandom(1, 0.3) * $volatilityFactor;

        // Calcul de la variation pour cette mise à jour
        // On divise la variation quotidienne par 24 pour obtenir une variation par update
        $variation = $trend * $randomFactor * ($maxDailyVariation / 24);
        // On limite la baisse à -50% maximum par update pour éviter des chutes brutales
        $variation = max($variation, -0.50);

        $newPrice = $currentPrice * (1 + $variation);

        // Arrondi à 2 décimales; le prix peut atteindre zéro progressivement
        return max(round($newPrice, 2), 0);
    }

    private function calculateWeightedTrend(array $prices): float
    {
        if (count($prices) < 2) {
            return 0;
        }

        $total       = 0;
        $totalWeight = 0;
        $count       = count($prices);
        
        for ($i = 1; $i < $count; $i++) {
            // Pondération exponentielle inverse (les prix récents ont plus de poids)
            $weight = pow(3, $count - $i);
            // Calcul du pourcentage de variation entre deux entrées
            $change = ($prices[$i - 1]->getPrice() - $prices[$i]->getPrice()) / $prices[$i]->getPrice();
            $total       += $change * $weight;
            $totalWeight += $weight;
        }
        
        return $totalWeight ? $total / $totalWeight : 0;
    }

    private function generateRandomPrice(float $basePrice, float $variationPercent): float
    {
        // Génère une variation aléatoire dans l'intervalle [-variationPercent, +variationPercent]
        $variation = mt_rand(-$variationPercent * 100, $variationPercent * 100) / 100;
        return round($basePrice * (1 + $variation), 2);
    }

    private function createPriceEntry(Product $product, float $price): void
    {
        // On s'assure que le prix ne soit pas négatif (même si on veut aller vers zéro, pas de négatif)
        $price = max($price, 0);
        
        // Mise à jour de l'entité Product avec le nouveau prix
        $product->setPrice($price);
        
        // Création de l'entrée d'historique dans ProductPrice
        $entry = (new ProductPrice())
            ->setProduct($product)
            ->setPrice($price)
            ->setTimestamp(new \DateTimeImmutable());
        
        $this->em->persist($entry);

        $this->logger->info(sprintf(
            'Produit %d - Nouveau prix enregistré : %.2f',
            $product->getId(),
            $price
        ));
    }

    private function getGaussianRandom(float $mean, float $stdDev): float 
    {
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();
        $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
        return $mean + $z * $stdDev;
    }
}