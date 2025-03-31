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
     * Itère sur chaque produit possédé par l'utilisateur et, si 24h se sont écoulées
     * depuis la dernière attribution pour ce produit, crédite sur son solde un pourcentage
     * de la valeur actuelle du produit (calculé à partir du dernier enregistrement dans ProductPrice).
     *
     * Il est nécessaire d'avoir dans l'entité User des méthodes telles que :
     * - getProducts() qui retourne la collection des produits possédés par l'utilisateur,
     * - getLastReferralRewardTimeForProduct(Product $product) et
     * - setLastReferralRewardTimeForProduct(Product $product, \DateTime $date)
     * pour gérer la date de dernière attribution par produit.
     */
    private function handleReferralRewards(EntityManagerInterface $em, User $user): void
    {
        $now = new \DateTime();

        // On suppose que getProducts() retourne l'ensemble des produits possédés par l'utilisateur.
        foreach ($user->getProduct() as $product) {
            // Récupération de la dernière date de récompense pour ce produit
            $lastRewardTime = $user->getLastReferralRewardTimeForProduct($product);
            if (!$lastRewardTime) {
                // Si aucune récompense n'a été attribuée, on considère que la dernière attribution date de plus de 24h
                $lastRewardTime = (clone $now)->modify('-25 hours');
            }

            // Si 24h (86400 secondes) se sont écoulées depuis la dernière attribution pour ce produit
            if (($now->getTimestamp() - $lastRewardTime->getTimestamp()) >= 86400) {
                // Récupération de la valeur actuelle du produit via le dernier prix enregistré
                $latestPrice = $em->getRepository(ProductPrice::class)->findLatestPrice($product);
                if (!$latestPrice) {
                    continue;
                }

                // Calcul de la récompense en fonction du taux de parrainage (ex: 0.4 pour 4%)
                $rewardRate = $user->getReferralRewardRate();
                $reward = $latestPrice->getPrice() * $rewardRate;

                // Crédite la récompense sur le solde de l'utilisateur
                $user->setBalance($user->getBalance() + $reward);

                // Met à jour la date de dernière attribution pour ce produit
                $user->setLastReferralRewardTimeForProduct($product, $now);

                $this->addFlash('success', sprintf(
                    'Vous avez reçu %.2f USD de récompense sur le produit %s !',
                    $reward,
                    $product->getSlug()
                ));
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
