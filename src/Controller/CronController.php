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
        // Récupère les 5 dernières entrées pour plus de réactivité
        $prices = $this->em->getRepository(ProductPrice::class)
            ->findBy(['product' => $product], ['timestamp' => 'DESC'], 5);

        // Utilisation du prix de base enregistré dans le produit si disponible, sinon une valeur par défaut
        $basePrice = $product->getPrice() ?? 100.00;

        if (empty($prices)) {
            $newPrice = $this->generateRandomPrice($basePrice, 0.30);
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
        $maxDailyVariation = 0.40; // 40% de variation maximale par jour
        $volatilityFactor  = 2.5;  // Amplificateur de volatilité
        $momentumFactor    = 0.5;  // Facteur de momentum (50% de la variation max par heure)

        // Calcul de la tendance pondérée
        $trend = $this->calculateWeightedTrend($priceHistory);

        // Composante principale (tendance + momentum)
        $baseVariation = $trend * $volatilityFactor * ($maxDailyVariation / 24);

        if ($trend > 0) {
            $baseVariation += $momentumFactor * ($maxDailyVariation / 24);
        } elseif ($trend < 0) {
            $baseVariation -= $momentumFactor * ($maxDailyVariation / 24);
        }

        // Composante aléatoire avec volatilité accrue
        $randomComponent = $this->getGaussianRandom(0, 3) * ($maxDailyVariation / 24);

        // Variation totale
        $variation = $baseVariation + $randomComponent;

        // Pression baissière accrue vers 0
        if ($currentPrice < 20) {
            $variation *= 1.5; // Augmente la volatilité de 50%
            $variation = max($variation, -0.4); // Limite la baisse à -40% par heure
        }

        // Limites de sécurité
        $variation = max($variation, -0.50); // Baisse max 50% par heure
        $variation = min($variation, 0.50);  // Montée max 50% par heure

        // Calcul du nouveau prix
        $newPrice = $currentPrice * (1 + $variation);
        $newPrice = max(round($newPrice, 2), 0);

        // Éviter le statisme (forcer une variation minimale)
        if ($newPrice == $currentPrice) {
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
        $totalWeight = 0;
        $count = count($prices);

        for ($i = 1; $i < $count; $i++) {
            $weight = pow(3, $count - $i); // Pondération exponentielle récente
            $change = ($prices[$i - 1]->getPrice() - $prices[$i]->getPrice()) / $prices[$i]->getPrice();
            $total += $change * $weight;
            $totalWeight += $weight;
        }

        return $totalWeight ? $total / $totalWeight : 0;
    }

    private function generateRandomPrice(float $basePrice, float $variationPercent): float
    {
        $variation = $this->getGaussianRandom(0, $variationPercent);
        return round($basePrice * (1 + $variation), 2);
    }

    private function createPriceEntry(Product $product, float $price): void
    {
        // On s'assure que le prix est positif
        $price = max($price, 0);

        // Création d'une nouvelle entrée historique
        $entry = (new ProductPrice())
            ->setProduct($product)
            ->setPrice($price)
            ->setTimestamp(new \DateTimeImmutable());

        // On ne touche plus au champ "price" de l'entité Product pour respecter la transparence des historiques
        // $product->setPrice($price);

        $this->em->persist($entry);
        $this->logger->info("Produit {$product->getId()} : Nouvelle entrée de prix → {$price}€");
    }

    private function getGaussianRandom(float $mean = 0, float $stdDev = 1): float
    {
        $u1 = mt_rand() / mt_getrandmax();
        $u2 = mt_rand() / mt_getrandmax();
        $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
        return $mean + $z * $stdDev;
    }
}