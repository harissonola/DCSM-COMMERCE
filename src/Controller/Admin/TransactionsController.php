<?php
// src/Controller/Admin/TransactionsController.php
namespace App\Controller\Admin;

use App\Repository\TransactionsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/admin/transactions')]
class TransactionsController extends AbstractController
{
    #[Route('/', name: 'admin_transactions_index', methods: ['GET'])]
    public function index(TransactionsRepository $transactionsRepository): Response
    {
        return $this->render('admin/transactions/index.html.twig', [
            'transactions' => $transactionsRepository->findBy([], ['createdAt' => 'DESC']),
        ]);
    }
}