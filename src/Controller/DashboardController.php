<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use App\Repository\ShopRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(ShopRepository $shopRepository): Response
    {
        $user = $this->getUser();

        if ($user) {
            // if ($user->getRoles()[0] == "ROLE_ADMIN") {
            //     return $this->redirectToRoute("app_dashboard"); //je dois changer ceci en la route admin
            // }
        } else {
            return $this->redirectToRoute("app_main");
        }

        $shops = $shopRepository->findAll();

        return $this->render('dashboard/index.html.twig', [
            'controller_name' => 'DashboardController',
            'shops' => $shops,
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
            return $this->redirectToRoute("app_main");
        }

        $shops = $shopRepository->findAll();

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
            return $this->redirectToRoute("app_main");
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
