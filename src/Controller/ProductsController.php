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

        if (!$user) {
            return $this->redirectToRoute("app_main");
        }

        // Trouver le produit correspondant au slug
        $prod = $productRepository->findOneBy(['slug' => $slug]);

        if (!$prod) {
            throw $this->createNotFoundException('Produit non trouvé');
        }

        // Vérifier si l'utilisateur est autorisé à voir ce produit
        if (!in_array('ROLE_ADMIN', $user->getRoles()) && !$prod->getUsers()->contains($user)) {
            $this->addFlash('danger', 'Vous devez acheter ce produit pour accéder à son tableau de bord. Cliquez ici pour acheter le produit : <a href="' . $urlGenerator->generate('app_buy_product', ['slug' => $slug]) . '" class="alert-link">Acheter ce produit</a>');
            return $this->redirectToRoute('app_main');
        }

        // Vérification du bot de minage (si actif)
        if ($user->isMiningBotActive()) {
            $this->startProductMining($prod, $entityManager);
        }

        // Récupérer UNIQUEMENT les prix du produit sélectionné
        $prices = $productPriceRepository->findBy(
            ['product' => $prod],
            ['timestamp' => 'ASC']
        );

        $chartData = [];
        foreach ($prices as $price) {
            $chartData[] = [
                'x' => $price->getTimestamp()->format('Y-m-d H:i:s'),
                'y' => $price->getPrice()
            ];
        }

        return $this->render('products/dash.html.twig', [
            'prod' => $prod,
            'chartData' => json_encode($chartData),
        ]);
    }






    #[Route('/{slug}/buy', name: 'app_buy_product')]
    public function buy(string $slug, ProductRepository $productRepository, UrlGeneratorInterface $urlGenerator): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute("app_main");
        }

        $prod = $productRepository->findOneBy(['slug' => $slug]);
        if (!$prod) {
            throw $this->createNotFoundException("Produit introuvable !");
        }

        // Récupérer les valeurs depuis .env
        $apiKey = $_ENV['CINETPAY_API_KEY'];
        $siteId = $_ENV['CINETPAY_SITE_ID'];
        $paydunyaPublicKey = $_ENV['PAYDUNYA_PUBLIC_KEY'];
        $paydunyaPrivateKey = $_ENV['PAYDUNYA_PRIVATE_KEY'];
        $paydunyaToken = $_ENV['PAYDUNYA_TOKEN'];

        $transactionId = uniqid("ORDER_");
        $notifyUrl = $urlGenerator->generate('app_cinetpay_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $returnUrl = $urlGenerator->generate('app_payment_success', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $cancelUrl = $urlGenerator->generate('app_payment_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('products/buy.html.twig', [
            'prod' => $prod,
            'apiKey' => $apiKey,
            'siteId' => $siteId,
            'transactionId' => $transactionId,
            'notifyUrl' => $notifyUrl,
            'returnUrl' => $returnUrl,
            'cancelUrl' => $cancelUrl,
            'paydunyaPublicKey' => $paydunyaPublicKey,
            'paydunyaPrivateKey' => $paydunyaPrivateKey,
            'paydunyaToken' => $paydunyaToken,
        ]);
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
