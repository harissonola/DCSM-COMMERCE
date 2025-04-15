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
            'user_count' => $userRepository->count([]),
            'product_count' => $productRepository->count([]),
            'shop_count' => $shopRepository->count([]),
            'recent_transactions' => $transactionsRepository->findBy([], ['createdAt' => 'DESC'], 5),
        ]);
    }
}