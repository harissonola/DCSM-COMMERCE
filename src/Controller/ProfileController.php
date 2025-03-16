<?php

namespace App\Controller;

use App\Entity\Transactions;
use App\Repository\TransactionsRepository;
use App\Service\CryptoService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile')]
    public function index(TransactionsRepository $transactionsRepository): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $transactions = $transactionsRepository->findBy(['user' => $this->getUser()]);

        return $this->render('profile/index.html.twig', [
            'transactions' => $transactions,
        ]);
    }
}