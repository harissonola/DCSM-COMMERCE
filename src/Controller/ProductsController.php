<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Product;
use App\Entity\ProductPrice;
use App\Repository\ProductPriceRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use DateTime;
use NumberFormatter;
use Psr\Log\LoggerInterface;

#[Route('/products', name: 'app_products_')]
class ProductsController extends AbstractController
{
    private NumberFormatter $numberFormatter;
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {

        $this->logger = $logger;
        $this->numberFormatter = new NumberFormatter('en_US', NumberFormatter::CURRENCY);
    }

    #[Route('/', name: 'index')]
    public function index(ProductRepository $productRepository): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute("app_dashboard");
        }
        return $this->render('products/index.html.twig', [
            'products' => $productRepository->findAll(),
        ]);
    }

    #[Route('/{slug}/dashboard', name: 'dashboard')]
    public function dashboard(
        string $slug,
        ProductRepository $productRepository,
        ProductPriceRepository $priceRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute("app_dashboard");
        }

        $product = $productRepository->findOneBy(['slug' => $slug]);
        if (!$product) {
            throw $this->createNotFoundException('Produit introuvable');
        }

        if (!in_array('ROLE_ADMIN', $user->getRoles()) && !$product->getUsers()->contains($user)) {
            $this->addFlash('danger', $this->generateAccessMessage($slug));
            return $this->redirectToRoute('app_dashboard');
        }

        // Nouveau système : on parcourt les produits possédés par l'utilisateur
        // et on attribue la récompense correspondante si 24h se sont écoulées depuis la dernière attribution.
        $this->handleReferralRewards($em, $user);

        return $this->render('products/dash.html.twig', [
            'prod'      => $product,
            'chartData' => $this->generateChartData($product, $priceRepository),
            'balance'   => $user->getBalance()
        ]);
    }

    /**
     * Nouvelle action pour renvoyer les données du graphique en fonction d'une plage de temps.
     * Exemple d'URL : /products/{slug}/dashboard/data?range=1d
     */
    #[Route('/{slug}/dashboard/data', name: 'dashboard_data')]
    public function dashboardData(
        string $slug,
        Request $request,
        ProductRepository $productRepository,
        ProductPriceRepository $priceRepository
    ): JsonResponse {
        $product = $productRepository->findOneBy(['slug' => $slug]);
        if (!$product) {
            throw $this->createNotFoundException("Produit introuvable");
        }

        // Récupérer la plage de temps souhaitée via le paramètre "range"
        $range = $request->query->get('range', '1d');
        $startDate = null;

        switch ($range) {
            case '1d':
                $startDate = (new \DateTime())->modify('-1 day');
                break;
            case '5d':
                $startDate = (new \DateTime())->modify('-5 day');
                break;
            case '1m':
                $startDate = (new \DateTime())->modify('-1 month');
                break;
            case 'ytd':
                // Premier jour de l'année en cours
                $startDate = new \DateTime('first day of January ' . date('Y'));
                break;
            case '1y':
                $startDate = (new \DateTime())->modify('-1 year');
                break;
            case '5y':
                $startDate = (new \DateTime())->modify('-5 year');
                break;
            case 'max':
                $startDate = null;
                break;
            default:
                $startDate = (new \DateTime())->modify('-1 day');
        }

        // Récupérer les prix filtrés par date si nécessaire
        if ($startDate) {
            $prices = $priceRepository->createQueryBuilder('pp')
                ->andWhere('pp.product = :product')
                ->andWhere('pp.timestamp >= :startDate')
                ->setParameter('product', $product)
                ->setParameter('startDate', $startDate)
                ->orderBy('pp.timestamp', 'ASC')
                ->getQuery()
                ->getResult();
        } else {
            $prices = $priceRepository->findBy(['product' => $product], ['timestamp' => 'ASC']);
        }

        $data = ['price' => [], 'market_cap' => []];
        $exchangeRate = 601.5;

        foreach ($prices as $price) {
            $timestamp = $price->getTimestamp()->format('c');
            $data['price'][] = ['x' => $timestamp, 'y' => round($price->getPrice() / $exchangeRate, 2)];
            if ($price->getMarketCap() !== null) {
                $data['market_cap'][] = ['x' => $timestamp, 'y' => round($price->getMarketCap() / $exchangeRate, 2)];
            }
        }
        return new JsonResponse($data);
    }

    /**
     * Attribue des récompenses à tous les utilisateurs qui possèdent au moins un produit,
     * qu'ils soient connectés ou non, si 24 heures se sont écoulées depuis la dernière attribution.
     */
    private function handleReferralRewards(EntityManagerInterface $em, User $currentUser): void
    {
        $now = new \DateTime();

        // Récupérer tous les utilisateurs qui possèdent au moins un produit
        $userRepository = $em->getRepository(User::class);
        $usersWithProducts = $userRepository->createQueryBuilder('u')
            ->join('u.product', 'p')
            ->distinct()
            ->getQuery()
            ->getResult();

        foreach ($usersWithProducts as $user) {
            // Vérifier si l'utilisateur a déjà reçu une récompense globale dans les dernières 24 heures
            $lastGlobalRewardTime = $user->getLastReferralRewards(); // Méthode existante
            if ($lastGlobalRewardTime && ($now->getTimestamp() - $lastGlobalRewardTime->getTimestamp()) < 86400) {
                continue;
            }

            $totalReward = 0;
            foreach ($user->getProduct() as $product) {
                $latestPrice = $em->getRepository(ProductPrice::class)->findLatestPrice($product);
                if (!$latestPrice) {
                    continue;
                }

                // Conversion du prix en CFA en USD en utilisant le numberFormatter
                $priceValue = $latestPrice->getPrice(); // Prix en CFA
                $formattedPrice = $this->numberFormatter->formatCurrency($priceValue, 'USD');
                $priceUSD = $formattedPrice ? (float) str_replace(['$', ','], ['', ''], $formattedPrice) : 0.0;

                $rewardRate = $user->getReferralRewardRate();
                // Calcul de la récompense en USD en appliquant le pourcentage
                $reward = $priceUSD * ($rewardRate / 100);
                $totalReward += $reward;
            }

            // Si une récompense a été calculée pour au moins un produit, créditer le compte
            if ($totalReward > 0) {
                $user->setBalance($user->getBalance() + $totalReward);
                $user->setLastReferralRewards($now); // Mise à jour de la date globale de récompense

                // Ajouter le message flash seulement pour l'utilisateur actuellement connecté
                if ($user->getId() === $currentUser->getId()) {
                    $formattedReward = $this->numberFormatter->formatCurrency($totalReward, 'USD');
                    $this->addFlash('success', sprintf(
                        'Vous avez reçu %s de récompense pour vos produits !',
                        $formattedReward
                    ));
                }

                // Journalisation de la récompense attribuée
                $this->logger->info('Récompense attribuée', [
                    'user_id'   => $user->getId(),
                    'reward'    => $totalReward,
                    'timestamp' => $now->format('Y-m-d H:i:s')
                ]);
            }
        }

        $em->flush();
    }


    private function generateChartData(Product $product, ProductPriceRepository $priceRepository): array
    {
        $data = ['price' => [], 'market_cap' => []];
        $exchangeRate = 601.5;

        foreach ($priceRepository->findBy(['product' => $product], ['timestamp' => 'ASC']) as $price) {
            $timestamp = $price->getTimestamp()->format('c');
            $data['price'][] = ['x' => $timestamp, 'y' => round($price->getPrice() / $exchangeRate, 2)];

            if ($price->getMarketCap() !== null) {
                $data['market_cap'][] = ['x' => $timestamp, 'y' => round($price->getMarketCap() / $exchangeRate, 2)];
            }
        }
        return $data;
    }

    private function generateAccessMessage(string $slug): string
    {
        return sprintf(
            'Accès refusé. <a href="%s" class="alert-link">Acheter le produit</a> pour accéder au dashboard.',
            $this->generateUrl('sell_product', ['slug' => $slug], UrlGeneratorInterface::ABSOLUTE_URL)
        );
    }

    #[Route('/sell-product/{slug}', name: 'sell_product', methods: ['POST'])]
    public function sellProduct(
        Request $request,
        ProductRepository $productRepository,
        EntityManagerInterface $em,
        string $slug,
        NumberFormatter $numberFormatter
    ): Response {
        try {
            /** @var User $user */
            $user = $this->getUser();
            if (!$user) {
                $this->addFlash('error', 'Non authentifié');
                return $this->redirectToRoute('app_login');
            }

            $product = $productRepository->findOneBy(['slug' => $slug]);
            if (!$product) {
                $this->addFlash('error', 'Produit introuvable');
                return $this->redirectToRoute('app_dashboard');
            }

            // Utiliser la même logique que {{ prod.price|format_currency('USD') }}
            $formattedPrice = $numberFormatter->formatCurrency($product->getPrice(), 'USD');
            // Convertir la chaîne formatée en nombre sans le symbole $
            $priceUSD = $formattedPrice ? (float) str_replace(['$', ','], ['', ''], $formattedPrice) : 0.0;

            $this->logger->info('Tentative d\'achat', [
                'balance' => $user->getBalance(),
                'price_usd' => $priceUSD,
                'formatted_price' => $formattedPrice
            ]);

            if ($user->getBalance() < $priceUSD) {
                $this->addFlash('error', 'Solde insuffisant');
                return $this->redirectToRoute('app_dashboard');
            }

            $user->setBalance($user->getBalance() - $priceUSD);
            $user->addProduct($product);
            $em->flush();

            $this->addFlash('success', 'Achat réussi !');
            return $this->redirectToRoute('app_dashboard');
        } catch (\Throwable $e) {
            $this->logger->error('Erreur transaction produit', [
                'error' => $e->getMessage()
            ]);

            $this->addFlash('error', 'Une erreur est survenue lors du traitement de l\'achat');
            return $this->redirectToRoute('app_dashboard');
        }
    }

    #[Route('/cinetpay-callback', name: 'cinetpay_callback')]
    public function cinetpayCallback(Request $request): Response
    {
        $status = $request->query->get('status');
        $this->addFlash(
            $status === 'ACCEPTED' ? 'success' : 'error',
            $status === 'ACCEPTED'
                ? 'Paiement accepté !'
                : 'Échec du paiement'
        );
        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/paydunya-callback', name: 'paydunya_callback')]
    public function paydunyaCallback(Request $request): Response
    {
        $status = $request->query->get('status');
        $this->addFlash(
            $status === 'completed' ? 'success' : 'error',
            $status === 'completed'
                ? 'Paiement réussi avec PayDunya'
                : 'Échec du paiement PayDunya'
        );
        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/payment/success', name: 'payment_success')]
    public function paymentSuccess(): Response
    {
        return $this->render('payment/success.html.twig', [
            'message' => 'Paiement accepté avec succès !'
        ]);
    }

    #[Route('/payment/cancel', name: 'payment_cancel')]
    public function paymentCancel(): Response
    {
        return $this->render('payment/cancel.html.twig', [
            'message' => 'Paiement annulé ou échoué'
        ]);
    }
}
// src/Controller/ProductsController.php