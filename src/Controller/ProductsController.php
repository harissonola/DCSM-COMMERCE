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
use DateTimeZone;

#[Route('/products', name: 'app_products_')]
class ProductsController extends AbstractController
{
    #[Route('/', name: 'index')]
    public function index(): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute("app_main");
        }
        return $this->render('products/index.html.twig');
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
        if (!$user) return $this->redirectToRoute("app_main");

        $product = $productRepository->findOneBy(['slug' => $slug]);
        if (!$product) throw $this->createNotFoundException('Produit introuvable');

        if (!in_array('ROLE_ADMIN', $user->getRoles()) && !$product->getUsers()->contains($user)) {
            $this->addFlash('danger', $this->generateAccessMessage($slug));
            return $this->redirectToRoute('app_main');
        }

        if ($user->isMiningBotActive()) {
            $this->handleAutomaticMining($product, $em, $user);
        }

        return $this->render('products/dash.html.twig', [
            'prod' => $product,
            'chartData' => $this->generateChartData($product, $priceRepository),
            'reward' => $user->getReward()
        ]);
    }

    #[Route('/buy-mining-bot', name: 'buy_mining_bot')]
    public function buyMiningBot(EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $botPrice = 50.00; // Prix en USD

        if (!$user) {
            return $this->redirectToRoute("app_main");
        }

        if ($user->getBalance() >= $botPrice) {
            // Déduire le prix et activer le bot
            $user->setBalance($user->getBalance() - $botPrice);
            $user->setIsMiningBotActive(true);
            $em->flush();

            $this->addFlash('success', 'Bot de minage activé avec succès !');
        } else {
            $this->addFlash('error', 'Solde insuffisant pour acheter le bot');
        }

        return $this->redirectToRoute('app_main');
    }

    private function handleAutomaticMining(Product $product, EntityManagerInterface $em, User $user): void
    {
        $lastPrice = $em->getRepository(ProductPrice::class)
            ->findOneBy(['product' => $product], ['timestamp' => 'DESC']);

        if (!$lastPrice || $lastPrice->getTimestamp()->modify('+5 minutes') < new \DateTime()) {
            $this->generateNewPrice($product, $em);
        }

        $this->calculateMiningRewards($user, $product, $em);
    }

    private function calculateMiningRewards(User $user, Product $product, EntityManagerInterface $em): void
    {
        $now = new \DateTime();
        $lastMining = $user->getLastMiningTime() ?? $now;
        $interval = $now->diff($lastMining);
        $hours = $interval->h + ($interval->days * 24) + ($interval->i / 60);

        $latestPrice = $em->getRepository(ProductPrice::class)->findLatestPrice($product);
        if (!$latestPrice) return;

        $rewardRate = match($product->getShop()->getSlug()) {
            'vip-a' => 0.002,
            'vip-b' => 0.0035,
            'vip-c' => 0.005,
            default => 0.001
        };

        $reward = $hours * ($latestPrice->getPrice() / 601.5) * $rewardRate;
        $user->setReward(round($user->getReward() + $reward, 2));
        $user->setLastMiningTime($now);
        $em->flush();
    }

    #[Route('/claim-rewards', name: 'claim_rewards')]
    public function claimRewards(EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if ($user->getReward() > 0) {
            $user->setBalance($user->getBalance() + $user->getReward());
            $user->setReward(0);
            $em->flush();
            $this->addFlash('success', sprintf('%.2f USD transférés à votre solde !', $user->getReward()));
        }
        return $this->redirectToRoute('app_main');
    }

    #[Route('/{slug}/manual-mining', name: 'manual_mining')]
    public function manualMining(
        string $slug,
        ProductRepository $productRepository,
        EntityManagerInterface $em
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        $product = $productRepository->findOneBy(['slug' => $slug]);

        if ($product && !$user->isMiningBotActive()) {
            $latestPrice = $em->getRepository(ProductPrice::class)->findLatestPrice($product);
            $reward = ($latestPrice->getPrice() / 601.5) * 0.0008;
            $user->setReward(round($user->getReward() + $reward, 2));
            $em->flush();
            $this->addFlash('success', sprintf('+%.2f USD (minage manuel)', $reward));
        }
        return $this->redirectToRoute('app_products_dashboard', ['slug' => $slug]);
    }

    private function generateNewPrice(Product $product, EntityManagerInterface $em): void
    {
        $shopSlug = $product->getShop()->getSlug();
        $priceRanges = [
            'vip-a' => [0, 50000],
            'vip-b' => [0, 85000],
            'vip-c' => [0, 150000],
        ];

        [$min, $max] = $priceRanges[$shopSlug] ?? [0, 500];
        $priceValue = mt_rand($min, $max);

        $price = (new ProductPrice())
            ->setProduct($product)
            ->setPrice($priceValue)
            ->setTimestamp(new \DateTimeImmutable('now', new DateTimeZone('Africa/Porto-Novo')));

        $em->persist($price);
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
            $this->generateUrl('app_buy_product', ['slug' => $slug], UrlGeneratorInterface::ABSOLUTE_URL)
        );
    }

    // Méthodes existantes inchangées
    #[Route('/sell-product/{slug}', name: 'sell_product', methods: ['POST'])]
    public function sellProduct(
        Request $request,
        ProductRepository $productRepository,
        EntityManagerInterface $em,
        string $slug
    ): JsonResponse {
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => false, 'message' => 'Requête invalide'], 400);
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['success' => false, 'message' => 'Non authentifié'], 401);
        }

        $product = $productRepository->findOneBy(['slug' => $slug]);
        if (!$product) {
            return new JsonResponse(['success' => false, 'message' => 'Produit introuvable'], 404);
        }

        $priceUSD = $product->getPrice() / 601.56;
        if ($user->getBalance() < $priceUSD) {
            return new JsonResponse(['success' => false, 'message' => 'Solde insuffisant'], 200);
        }

        $user->setBalance($user->getBalance() - $priceUSD);
        $user->addProduct($product);
        $em->flush();

        return new JsonResponse(['success' => true, 'message' => 'Achat réussi']);
    }

    #[Route('/cinetpay-callback', name: 'cinetpay_callback')]
    public function cinetpayCallback(Request $request): Response
    {
        $status = $request->query->get('status');
        $this->addFlash($status === 'ACCEPTED' ? 'success' : 'error', 
            $status === 'ACCEPTED' 
            ? 'Paiement accepté !' 
            : 'Échec du paiement');
        return $this->redirectToRoute('app_main');
    }

    #[Route('/paydunya-callback', name: 'paydunya_callback')]
    public function paydunyaCallback(Request $request): Response
    {
        $status = $request->query->get('status');
        $this->addFlash($status === 'completed' ? 'success' : 'error', 
            $status === 'completed' 
            ? 'Paiement réussi avec PayDunya' 
            : 'Échec du paiement PayDunya');
        return $this->redirectToRoute('app_main');
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