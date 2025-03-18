<?php

namespace App\Controller;

use App\Repository\TransactionsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile')]
    public function index(TransactionsRepository $transactionsRepository): Response
    {
        // Redirection si l'utilisateur n'est pas connecté
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        // Récupération des transactions
        $transactions = $transactionsRepository->findBy(['user' => $this->getUser()]);

        // Génération du lien d'affiliation
        // On suppose que l'utilisateur possède un champ "referralCode"
        $referralCode = $this->getUser()->getReferralCode();
        $referralLink = $this->generateUrl(
            'app_register',
            ['ref' => $referralCode],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return $this->render('profile/index.html.twig', [
            'transactions' => $transactions,
            'referralLink' => $referralLink, // on envoie le lien au template
        ]);
    }
}