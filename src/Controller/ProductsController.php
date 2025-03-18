<?php

namespace App\Controller;

use App\Repository\ProductPriceRepository;
use App\Repository\ProductRepository;
use App\Entity\ProductPrice;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

#[Route('/products', name: '')]
class ProductsController extends AbstractController
{

    #[Route('/', name: 'app_products')]
    public function index(): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute("app_main");
        }

        return $this->render('products/index.html.twig', [
            'controller_name' => 'ProductsController',
        ]);
    }





    #[Route('/{slug}/dashboard', name: 'app_dashboard_product')]
    public function dash(
        $slug,
        ProductRepository $productRepository,
        ProductPriceRepository $productPriceRepository,
        EntityManagerInterface $entityManager,
        UrlGeneratorInterface $urlGenerator
    ): Response {
        $user = $this->getUser();

        // Redirection si utilisateur non connecté
        if (!$user) {
            return $this->redirectToRoute("app_main");
        }

        // Récupération du produit
        $prod = $productRepository->findOneBy(['slug' => $slug]);
        if (!$prod) {
            throw $this->createNotFoundException('Produit non trouvé');
        }

        // Vérification des permissions
        if (!in_array('ROLE_ADMIN', $user->getRoles()) && !$prod->getUsers()->contains($user)) {
            $this->addFlash('danger', 'Accès refusé. <a href="' .
                $urlGenerator->generate('app_buy_product', ['slug' => $slug]) .
                '" class="alert-link">Acheter le produit</a>');
            return $this->redirectToRoute('app_main');
        }

        // Activation du bot de minage
        if ($user->isMiningBotActive()) {
            $this->startProductMining($prod, $entityManager);
        }

        // Récupération des données historiques
        $prices = $productPriceRepository->findBy(
            ['product' => $prod],
            ['timestamp' => 'ASC']
        );

        // Préparation des données pour le graphique
        $chartData = [
            'price' => [],
            'market_cap' => []
        ];

        foreach ($prices as $price) {
            $timestamp = $price->getTimestamp()->format('c'); // Format ISO 8601

            // Données de prix
            $chartData['price'][] = [
                'x' => $timestamp,
                'y' => $price->getPrice()
            ];

            // Données de capitalisation (si disponible)
            if (method_exists($price, 'getMarketCap') && $price->getMarketCap() !== null) {
                $chartData['market_cap'][] = [
                    'x' => $timestamp,
                    'y' => $price->getMarketCap()
                ];
            }
        }

        return $this->render('products/dash.html.twig', [
            'prod' => $prod,
            'chartData' => $chartData
        ]);
    }



    /**
     * Route pour finaliser l'achat d'un produit.
     * Le prix du produit est stocké en CFA dans la base de données et le solde de l'utilisateur en USD.
     * On suppose ici que 600 CFA = 1 USD.
     */
    #[Route('/sell-product/{slug}', name: 'app_sell_product', methods: ['POST'])]
    public function sellProduct(
        Request $request,
        ProductRepository $productRepository,
        EntityManagerInterface $em,
        string $slug
    ): JsonResponse {
        // Vérifier que la requête est de type AJAX
        if (!$request->isXmlHttpRequest()) {
            return new JsonResponse(['success' => false, 'message' => 'Requête invalide'], 400);
        }

        // Récupérer l'utilisateur courant
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['success' => false, 'message' => 'Utilisateur non authentifié'], 401);
        }

        // Récupérer le produit via son slug
        $product = $productRepository->findOneBy(['slug' => $slug]);
        if (!$product) {
            return new JsonResponse(['success' => false, 'message' => 'Produit introuvable'], 404);
        }

        // Taux de conversion : 601.56 CFA = 1 USD
        $conversionRate = 601.56;
        $priceCFA = $product->getPrice(); // Prix en CFA
        $priceUSD = $priceCFA / $conversionRate; // Prix converti en USD

        // Vérifier si le solde de l'utilisateur est suffisant
        if ($user->getBalance() < $priceUSD) {
            return new JsonResponse(['success' => false, 'message' => 'Solde insuffisant'], 200);
        }

        // Déduire le montant du solde utilisateur
        $user->setBalance($user->getBalance() - $priceUSD);

        // Optionnel : Enregistrer une transaction ou associer le produit à l'utilisateur
        $user->addProduct($product);

        $em->persist($user);
        $em->flush();

        return new JsonResponse(['success' => true, 'message' => 'Achat réussi'], 200);
    }

    #[Route('/{slug}/start-mining', name: 'start_mining', methods: ['POST'])]
    public function startMining(
        string $slug,
        ProductRepository $productRepository,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->getUser()) {
            return $this->redirectToRoute("app_main");
        }

        $prod = $productRepository->findOneBy(['slug' => $slug]);
        if (!$prod) {
            throw $this->createNotFoundException('Produit introuvable');
        }

        // Vérifier si l'utilisateur a le bot de minage actif
        if ($this->getUser()->isMiningBotActive()) {
            // Minage automatique
            $this->startProductMining($prod);
            $this->addFlash('success', 'Le minage automatique a été démarré.');
        } else {
            // Si l'utilisateur n'a pas le bot, lui permettre de miner manuellement
            $this->addFlash('warning', 'Vous n\'avez pas de bot de minage actif. Vous pouvez acheter un abonnement.');
            return $this->redirectToRoute('app_buy_product', ['slug' => $slug]);
        }

        return $this->redirectToRoute('app_dashboard_product', ['slug' => $slug]);
    }

    #[Route('/{slug}/start-manual-mining', name: 'start_manual_mining')]
    public function startManualMining(string $slug, ProductRepository $productRepository): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute("app_main");
        }

        // Récupérer le produit par son slug
        $prod = $productRepository->findOneBy(['slug' => $slug]);
        if (!$prod) {
            throw $this->createNotFoundException('Produit non trouvé');
        }

        // Vérifier si l'utilisateur a un bot de minage
        if (!$this->getUser()->isMiningBotActive()) {
            // Si l'utilisateur n'a pas de bot, démarrer le minage manuel
            // (ici tu peux ajouter la logique de minage manuel)

            // Par exemple : commencer le minage manuellement
            // Ton code pour gérer le minage manuel ici (par exemple, ajouter un enregistrement de transaction ou un état de minage)
            $this->addFlash('success', 'Le minage manuel a été démarré pour le produit : ' . $prod->getName());
        } else {
            // L'utilisateur possède déjà le bot, donc rediriger ou indiquer que le minage automatique est en cours
            $this->addFlash('error', 'Vous avez déjà un bot de minage actif.');
        }

        return $this->redirectToRoute('app_dashboard_product', ['slug' => $slug]);
    }

    #[Route('/buy-mining-bot', name: 'buy_mining_bot')]
    public function buyMiningBot(): Response
    {
        // Ici tu devras rediriger l'utilisateur vers la page d'achat du bot de minage
        // Par exemple, afficher une page ou un formulaire pour effectuer le paiement

        return $this->render('products/buy_mining_bot.html.twig');
    }

    private function startProductMining($prod, EntityManagerInterface $entityManager)
    {
        $user = $this->getUser();
        // Récupérer le slug du shop associé au produit
        $shopSlug = $prod->getShop()->getSlug();

        // Définir les plages de prix en fonction du shop
        $priceRanges = [
            'vip-a' => [0, 50000],
            'vip-b' => [0, 85000],
            'vip-c' => [0, 150000],
        ];

        // Vérifier si le shopSlug existe dans les plages définies
        if (isset($priceRanges[$shopSlug])) {
            [$minPrice, $maxPrice] = $priceRanges[$shopSlug];
        } else {
            // Si le shopSlug n'est pas défini, utiliser une plage par défaut
            [$minPrice, $maxPrice] = [0, 500];
        }

        // Génération d'un prix aléatoire dans la plage définie
        $generatedPrice = mt_rand($minPrice, $maxPrice);

        // Création et enregistrement du nouveau prix
        $price = new ProductPrice();
        $price->setProduct($prod)
            ->setPrice($generatedPrice)
            ->setTimestamp(new \DateTimeImmutable('now', new \DateTimeZone('Africa/Porto-Novo')))
        ;

        $entityManager->persist($price);
        $entityManager->flush();
    }


    #[Route('/cinetpay-callback', name: 'app_cinetpay_callback')]
    public function cinetpayCallback(Request $request, EntityManagerInterface $entityManager): Response
    {
        $transactionId = $request->query->get('transaction_id');
        $status = $request->query->get('status');

        if ($status === 'ACCEPTED') {
            $this->addFlash('success', 'Votre paiement a été accepté.');
        } else {
            $this->addFlash('error', 'Le paiement a échoué.');
        }

        return $this->redirectToRoute('app_main');
    }

    #[Route('/paydunya-callback', name: 'app_paydunya_callback')]
    public function paydunyaCallback(Request $request, EntityManagerInterface $entityManager): Response
    {
        $status = $request->query->get('status');
        $transactionId = $request->query->get('invoice_token');

        if ($status === 'completed') {
            $this->addFlash('success', 'Paiement réussi avec PayDunya.');
        } else {
            $this->addFlash('error', 'Le paiement a échoué avec PayDunya.');
        }

        return $this->redirectToRoute('app_main');
    }

    #[Route('/payment/success', name: 'app_payment_success')]
    public function paymentSuccess(): Response
    {
        return $this->render('payment/success.html.twig', [
            'message' => 'Votre paiement a été accepté. Merci !',
        ]);
    }

    #[Route('/payment/cancel', name: 'app_payment_cancel')]
    public function paymentCancel(): Response
    {
        return $this->render('payment/cancel.html.twig', [
            'message' => 'Le paiement a été annulé. Veuillez réessayer.',
        ]);
    }
}
