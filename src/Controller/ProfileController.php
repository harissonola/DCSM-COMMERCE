<?php

namespace App\Controller;

use App\Repository\TransactionsRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Mime\Address;

final class ProfileController extends AbstractController
{
    // Dans ProfileController.php
    #[Route('/profile', name: 'app_profile')]
    public function index(TransactionsRepository $transactionsRepository, UserRepository $userRepository): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $user = $this->getUser();

        $transactions = $transactionsRepository->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC']
        );

        $referralLink = $this->generateUrl(
            'app_register',
            ['ref' => $user->getReferralCode()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // Récupération de tous les filleuls
        $allReferrals = $userRepository->findBy(['referrer' => $user]);

        // Séparation des filleuls actifs et inactifs
        $activeReferrals = [];
        $inactiveReferrals = [];

        foreach ($allReferrals as $referral) {
            $transactions = $transactionsRepository->findBy(['user' => $referral]);
            if (count($transactions) > 0) {
                $activeReferrals[] = $referral;
            } else {
                $inactiveReferrals[] = $referral;
            }
        }

        $referralCount = count($activeReferrals);
        $totalReferralsCount = count($allReferrals);
        $totalReferralRewards = $user->getTotalReferralRewards();
        $referredBy = $user->getReferrer();

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
            'inactiveReferrals' => $inactiveReferrals, // Nouveau
            'referralCount' => $referralCount,
            'referredBy' => $referredBy,
            'user' => $user,
            'referralStats' => $referralStats,
            'allReferrals' => $allReferrals,
        ]);
    }


    #[Route('/profile/send-message-to-inactive', name: 'app_send_message_to_inactive', methods: ['POST'])]
    public function sendMessageToInactive(UserRepository $userRepository, MailerInterface $mailer): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        // Récupérer les filleuls inactifs
        $allReferrals = $userRepository->findBy(['referrer' => $user]);
        $inactiveReferrals = array_filter($allReferrals, function ($referral) {
            return count($referral->getTransactions()) === 0;
        });

        // Envoyer un message à chaque filleul inactif
        foreach ($inactiveReferrals as $referral) {
            $email = (new TemplatedEmail())
                ->from(new Address('no-reply@votresite.com', 'Votre Plateforme'))
                ->to($referral->getEmail())
                ->subject('Rappel - Complétez votre inscription')
                ->htmlTemplate('emails/inactive_referral_reminder.html.twig')
                ->context([
                    'referral' => $referral,
                    'sender' => $user,
                ]);

            $mailer->send($email);
        }

        $this->addFlash('success', 'Message envoyé à ' . count($inactiveReferrals) . ' filleul(s) inactif(s)');

        return $this->redirectToRoute('app_profile');
    }
}
