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
    private const EXCHANGE_RATE = 601.5;
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
            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('danger', $this->generateAccessMessage($slug));
            return $this->redirectToRoute('app_dashboard');
        }

        $this->handleReferralRewards($em, $user);

        $chartData = $this->generateChartData($product, $priceRepository);
        
        $this->logger->debug('Chart data generated', [
            'product' => $product->getName(),
            'data_points' => count($chartData['price']),
            'has_market_cap' => !empty($chartData['market_cap'])
        ]);

        return $this->render('products/dash.html.twig', [
            'prod'      => $product,
            'chartData' => $chartData,
            'balance'   => $user->getBalance()
        ]);
    }

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

        $range = $request->query->get('range', '1d');
        $startDate = $this->getStartDateForRange($range);

        $prices = $this->getPricesForRange($priceRepository, $product, $startDate);
        $data = $this->formatChartData($prices);

        $this->logger->debug('Dashboard data response', [
            'range' => $range,
            'start_date' => $startDate ? $startDate->format('Y-m-d H:i:s') : null,
            'data_points' => count($data['price'])
        ]);

        return new JsonResponse($data);
    }

    private function handleReferralRewards(EntityManagerInterface $em, User $currentUser): void
    {
        $now = new \DateTimeImmutable();
        $userRepository = $em->getRepository(User::class);
        
        $usersWithProducts = $userRepository->createQueryBuilder('u')
            ->join('u.product', 'p')
            ->distinct()
            ->getQuery()
            ->getResult();

        foreach ($usersWithProducts as $user) {
            $lastRewardTime = $user->getLastReferralRewardAt();

            if ($lastRewardTime instanceof \DateTimeImmutable && 
                ($now->getTimestamp() - $lastRewardTime->getTimestamp()) < 86400) {
                continue;
            }

            $totalReward = $this->calculateTotalReward($em, $user);

            if ($totalReward > 0) {
                $user->setBalance($user->getBalance() + $totalReward);
                $user->setLastReferralRewardAt($now);

                if ($user->getId() === $currentUser->getId()) {
                    $formattedReward = $this->numberFormatter->formatCurrency($totalReward, 'USD');
                    // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('success', sprintf(
                        'Vous avez reçu %s de récompense pour vos produits !',
                        $formattedReward
                    ));
                }

                $this->logger->info('Récompense attribuée', [
                    'user_id' => $user->getId(),
                    'reward' => $totalReward,
                    'timestamp' => $now->format('Y-m-d H:i:s')
                ]);
            }
        }

        $em->flush();
    }

    private function generateChartData(Product $product, ProductPriceRepository $priceRepository): array
    {
        $prices = $priceRepository->findBy(['product' => $product], ['timestamp' => 'ASC']);
        return $this->formatChartData($prices);
    }

    private function getStartDateForRange(string $range): ?\DateTime
    {
        switch ($range) {
            case '1d': return (new \DateTime())->modify('-1 day');
            case '5d': return (new \DateTime())->modify('-5 day');
            case '1m': return (new \DateTime())->modify('-1 month');
            case 'ytd': return new \DateTime('first day of January ' . date('Y'));
            case '1y': return (new \DateTime())->modify('-1 year');
            case '5y': return (new \DateTime())->modify('-5 year');
            case 'max': return null;
            default: return (new \DateTime())->modify('-1 day');
        }
    }

    private function getPricesForRange(
        ProductPriceRepository $priceRepository,
        Product $product,
        ?\DateTime $startDate
    ): array {
        if ($startDate) {
            return $priceRepository->createQueryBuilder('pp')
                ->andWhere('pp.product = :product')
                ->andWhere('pp.timestamp >= :startDate')
                ->setParameter('product', $product)
                ->setParameter('startDate', $startDate)
                ->orderBy('pp.timestamp', 'ASC')
                ->getQuery()
                ->getResult();
        }
        
        return $priceRepository->findBy(['product' => $product], ['timestamp' => 'ASC']);
    }

    private function formatChartData(array $prices): array
    {
        $data = ['price' => [], 'market_cap' => []];

        foreach ($prices as $price) {
            $timestamp = $price->getTimestamp()->getTimestamp() * 1000; // Convertir en millisecondes
            $data['price'][] = [
                'x' => $timestamp, 
                'y' => round($price->getPrice() / self::EXCHANGE_RATE, 2)
            ];

            if ($price->getMarketCap() !== null) {
                $data['market_cap'][] = [
                    'x' => $timestamp, 
                    'y' => round($price->getMarketCap() / self::EXCHANGE_RATE, 2)
                ];
            }
        }

        return $data;
    }

    private function calculateTotalReward(EntityManagerInterface $em, User $user): float
    {
        $totalReward = 0;
        
        foreach ($user->getProduct() as $product) {
            $latestPrice = $em->getRepository(ProductPrice::class)->findLatestPrice($product);
            if (!$latestPrice) {
                continue;
            }

            $priceUSD = $latestPrice->getPrice() / self::EXCHANGE_RATE;
            $reward = $priceUSD * ($user->getReferralRewardRate() / 100);
            $totalReward += $reward;
        }

        return $totalReward;
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
        string $slug
    ): Response {
        try {
            /** @var User $user */
            $user = $this->getUser();
            if (!$user) {
                // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('error', 'Non authentifié');
                return $this->redirectToRoute('app_login');
            }

            $product = $productRepository->findOneBy(['slug' => $slug]);
            if (!$product) {
                // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('error', 'Produit introuvable');
                return $this->redirectToRoute('app_dashboard');
            }

            $priceUSD = $product->getPrice() / self::EXCHANGE_RATE;

            $this->logger->info('Tentative d\'achat', [
                'balance' => $user->getBalance(),
                'price_usd' => $priceUSD,
                'product' => $product->getName()
            ]);

            if ($user->getBalance() < $priceUSD) {
                // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('error', 'Solde insuffisant');
                return $this->redirectToRoute('app_dashboard');
            }

            $user->setBalance($user->getBalance() - $priceUSD);
            $user->addProduct($product);
            $em->flush();

            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('success', 'Achat réussi !');
            return $this->redirectToRoute('app_dashboard');
        } catch (\Throwable $e) {
            $this->logger->error('Erreur transaction produit', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('error', 'Une erreur est survenue lors du traitement de l\'achat');
            return $this->redirectToRoute('app_dashboard');
        }
    }

    #[Route('/cinetpay-callback', name: 'cinetpay_callback')]
    public function cinetpayCallback(Request $request): Response
    {
        $status = $request->query->get('status');
        // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add(
            $status === 'ACCEPTED' ? 'success' : 'error',
            $status === 'ACCEPTED' ? 'Paiement accepté !' : 'Échec du paiement'
        );
        return $this->redirectToRoute('app_dashboard');
    }

    #[Route('/paydunya-callback', name: 'paydunya_callback')]
    public function paydunyaCallback(Request $request): Response
    {
        $status = $request->query->get('status');
        // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add(
            $status === 'completed' ? 'success' : 'error',
            $status === 'completed' ? 'Paiement réussi avec PayDunya' : 'Échec du paiement PayDunya'
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