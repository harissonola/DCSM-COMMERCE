<?php

namespace App\Controller;

use App\Repository\ReferralCountRepository;
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
    public function index(
        TransactionsRepository $transactionsRepository,
        ReferralCountRepository $referralCountRepository
    ): Response
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

        $referred= $referralCountRepository->findBy(['referrer' => $referralCode]);
        $referredUsers = $referred ; //on doit stocker dans cette variable seulemet les Users qu'il a referencer et qui ont investir(ceux qui possedent au moins un produit)
        $referralCount = count($referredUsers);

        $referredBy = $referralCountRepository->findOneBy(['user' => $this->getUser()]);


        return $this->render('profile/index.html.twig', [
            'transactions' => $transactions,
            'referralLink' => $referralLink, // on envoie le lien au template
            'referred' => $referred,
            'referralCount' => $referralCount,
            'referredBy' => $referredBy,
        ]);
    }
}