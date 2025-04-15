<?php

namespace App\Controller;

use App\Repository\TransactionsRepository;
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

        // Récupérer les transactions de l'utilisateur connecté
        $transactions = $transactionsRepository->findBy(['user' => $this->getUser()]);

        // Génération du lien d'affiliation
        // On suppose que l'utilisateur possède une méthode getReferralCode()
        $referralCode = $this->getUser()->getReferralCode();
        $referralLink = $this->generateUrl(
            'app_register',
            ['ref' => $referralCode],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // Récupération de tous les filleuls (utilisateurs référés) via la relation
        $allReferrals = $this->getUser()->getReferrals();

        // Filtrer afin de ne conserver que les filleuls ayant investi
        // Par exemple, ici nous vérifions que l'utilisateur référé possède au moins une transaction
        $referredUsers = [];
        foreach ($allReferrals as $referral) {
            $userTransactions = $transactionsRepository->findBy(['user' => $referral]);
            if (count($userTransactions) > 0) {
                $referredUsers[] = $referral;
            }
        }
        $referralCount = count($referredUsers);

        // Récupérer le parrain de l'utilisateur (s'il existe)
        $referredBy = $this->getUser()->getReferrer();

        return $this->render('profile/index.html.twig', [
            'transactions'   => $transactions,
            'referralLink'   => $referralLink,
            'referred'       => $referredUsers, // Les filleuls ayant investi
            'referralCount'  => $referralCount,
            'referredBy'     => $referredBy,
        ]);
    }
}