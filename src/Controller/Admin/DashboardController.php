<?php
// src/Controller/Admin/DashboardController.php
namespace App\Controller\Admin;

use App\Repository\UserRepository;
use App\Repository\ProductRepository;
use App\Repository\ShopRepository;
use App\Repository\TransactionsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin')]
class DashboardController extends AbstractController
{
    #[Route('/', name: 'admin_dashboard')]
    public function index(
        UserRepository $userRepository,
        ProductRepository $productRepository,
        ShopRepository $shopRepository,
        TransactionsRepository $transactionsRepository
    ): Response {
        return $this->render('admin/dashboard/index.html.twig', [
            // Statistiques principales
            'users_count' => $userRepository->count([]),
            'new_users_last_month' => $userRepository->countLastMonth(),
            'products_count' => $productRepository->count([]),
            'new_products_last_month' => $productRepository->countLastMonth(),
            'shops_count' => $shopRepository->count([]),
            'active_shops' => $shopRepository->countActive(),
            'transactions_count' => $transactionsRepository->count([]),
            'transactions_amount' => $transactionsRepository->sumThisMonth(),

            // Données récentes
            'recent_transactions' => $transactionsRepository->findBy([], ['createdAt' => 'DESC'], 5),
            'recent_products' => $productRepository->findBy([], ['createdAt' => 'DESC'], 5),
            'recent_users' => $userRepository->findBy([], ['createdAt' => 'DESC'], 5),
        ]);
    }
}