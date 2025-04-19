<?php

namespace App\Controller;

use App\Repository\TransactionsRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile')]
    public function index(TransactionsRepository $transactionsRepository, UserRepository $userRepository): Response
    {
        // Redirection si l'utilisateur n'est pas connecté
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $user = $this->getUser();

        // Récupérer les transactions de l'utilisateur connecté
        $transactions = $transactionsRepository->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC']
        );

        // Génération du lien d'affiliation
        $referralLink = $this->generateUrl(
            'app_register',
            ['ref' => $user->getReferralCode()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // Récupération des filleuls
        $allReferrals = $user->getReferrals();
        $activeReferrals = $user->getActiveReferrals($transactionsRepository);
        $referralCount = count($activeReferrals);
        $totalReferralsCount = $user->getTotalReferralsCount();
        $totalReferralRewards = $user->getTotalReferralRewards();

        // Récupérer le parrain de l'utilisateur
        $referredBy = $user->getReferrer();

        // Statistiques supplémentaires
        $referralStats = [
            'total' => $totalReferralsCount,
            'active' => $referralCount,
            'inactive' => $totalReferralsCount - $referralCount,
            'rewards' => $totalReferralRewards,
        ];

        return $this->render('profile/index.html.twig', [
            'transactions' => $transactions,
            'referralLink' => $referralLink,
            'referred' => $activeReferrals,
            'referralCount' => $referralCount,
            'referredBy' => $referredBy,
            'user' => $user,
            'referralStats' => $referralStats,
            'allReferrals' => $allReferrals,
        ]);
    }

    #[Route('/profile/referrals', name: 'app_profile_referrals')]
    public function referralsList(UserRepository $userRepository): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $referrals = $user->getReferrals();

        return $this->render('profile/referrals.html.twig', [
            'referrals' => $referrals,
            'user' => $user,
        ]);
    }
}