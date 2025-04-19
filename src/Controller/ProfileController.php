<?php

namespace App\Controller;

use App\Repository\TransactionsRepository;
use App\Repository\UserRepository;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile')]
    public function index(
        TransactionsRepository $transactionsRepository,
        UserRepository $userRepository
    ): Response {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $user = $this->getUser();

        // Récupération des données
        $transactions = $transactionsRepository->findBy(
            ['user' => $user],
            ['createdAt' => 'DESC']
        );

        $referralLink = $this->generateUrl(
            'app_register',
            ['ref' => $user->getReferralCode()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        // Récupération et classification des filleuls
        $allReferrals = $userRepository->findBy(['referrer' => $user]);
        
        [$activeReferrals, $inactiveReferrals] = $this->classifyReferrals($allReferrals);

        // Préparation des statistiques
        $referralStats = [
            'total' => count($allReferrals),
            'active' => count($activeReferrals),
            'inactive' => count($inactiveReferrals),
            'rewards' => $user->getTotalReferralRewards(),
        ];

        return $this->render('profile/index.html.twig', [
            'transactions' => $transactions,
            'referralLink' => $referralLink,
            'referred' => $activeReferrals,
            'inactiveReferrals' => $inactiveReferrals,
            'referralCount' => $referralStats['active'],
            'referredBy' => $user->getReferrer(),
            'user' => $user,
            'referralStats' => $referralStats,
            'allReferrals' => $allReferrals,
        ]);
    }

    #[Route('/profile/send-message-to-inactive', name: 'app_send_message_to_inactive', methods: ['POST'])]
    public function sendMessageToInactive(
        UserRepository $userRepository,
        MailerInterface $mailer
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $allReferrals = $userRepository->findBy(['referrer' => $user]);
        $inactiveReferrals = array_filter($allReferrals, fn($ref) => $ref->getProduct()->isEmpty());

        foreach ($inactiveReferrals as $referral) {
            $this->sendReminderEmail($mailer, $referral, $user);
        }

        $this->addFlash(
            'success', 
            sprintf('Message envoyé à %d filleul(s) inactif(s)', count($inactiveReferrals))
        );

        return $this->redirectToRoute('app_profile');
    }

    /**
     * Classe les filleuls en actifs/inactifs selon leurs produits
     */
    private function classifyReferrals(array $referrals): array
    {
        $active = [];
        $inactive = [];

        foreach ($referrals as $referral) {
            if ($referral->getProduct()->count() > 0) {
                $active[] = $referral;
            } else {
                $inactive[] = $referral;
            }
        }

        return [$active, $inactive];
    }

    /**
     * Envoie l'email de rappel à un filleul inactif
     */
    private function sendReminderEmail(
        MailerInterface $mailer,
        $referral,
        $sender
    ): void {
        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@votresite.com', 'Votre Plateforme'))
            ->to($referral->getEmail(), $referral->getFname()." ".$referral->getFname())
            ->subject('Rappel - Complétez votre profil')
            ->htmlTemplate('emails/inactive_referral_reminder.html.twig')
            ->context([
                'referral' => $referral,
                'sender' => $sender,
                'app_name' => 'Votre Plateforme',
                'products_url' => $this->generateUrl('app_products_index', [], UrlGeneratorInterface::ABSOLUTE_URL),
            ]);

        $mailer->send($email);
    }
}