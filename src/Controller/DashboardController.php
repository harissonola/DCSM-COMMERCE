<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Repository\ShopRepository;
use DateTimeImmutable;
use NumberFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/', name: 'app_dashboard')]
    public function index(
        ShopRepository $shopRepository,
        ProductRepository $productRepository,
        NumberFormatter $numberFormatter
    ): Response
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->redirectToRoute("app_login");
        }

        //categories des produits
        $shops = $shopRepository->findAll(
            ['name' => 'ASC']
        );

        // Récupération de tous les produits triés par nom
        $products = $productRepository->findBy([], ['name' => 'ASC']);

        return $this->render('dashboard/home.html.twig', [
            'controller_name' => 'DashboardController',
            'shops' => $shops,
            'products' => $products,
        ]);
    }

    #[Route('/shop', name: 'app_shop_index')]
    public function shop(ShopRepository $shopRepository): Response
    {
        $user = $this->getUser();

        if ($user) {
            // if ($user->getRoles()[0] == "ROLE_ADMIN") {
            //     return $this->redirectToRoute("app_dashboard"); //je dois changer ceci en la route admin
            // }
        } else {
            return $this->redirectToRoute("app_dashboard");
        }

        $shops = $shopRepository->findAll(
            ['name' => 'ASC']
        );

        return $this->render('dashboard/index.html.twig', [
            'controller_name' => 'DashboardController',
            'shops' => $shops,
        ]);
    }

    #[Route('/shop/{slug}', name: 'app_shop')]
    public function shopProd(
        $slug,
        ShopRepository $shopRepository,
        ProductRepository $productRepository,
    ): Response
    {
        $user = $this->getUser();

        if ($user) {
            // if ($user->getRoles()[0] == "ROLE_ADMIN") {
            //     return $this->redirectToRoute("app_dashboard"); //je dois changer ceci en la route admin
            // }
        } else {
            return $this->redirectToRoute("app_dashboard");
        }

        $shop = $shopRepository->findOneBy(['slug' => $slug]);
        $prods = $productRepository->findBy(
            ['shop' => $shop],
            ['name' => 'ASC']
        );

        return $this->render('shop/index.html.twig', [
            'shop' => $shop,
            'prods' => $prods,
        ]);
    }
}
