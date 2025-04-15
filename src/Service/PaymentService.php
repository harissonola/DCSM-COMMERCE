<?php
namespace App\Service;

use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Transaction;
use App\Entity\User;

class PaymentService
{
    private EntityManagerInterface $em;
    
    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    public function processDeposit(User $user, float $amount, string $method, ?string $walletAddress = null)
    {
        switch ($method) {
            case 'card':
                return $this->processCardPayment($user, $amount);
            case 'mobilemoney':
                return $this->processMobileMoney($user, $amount);
            case 'paypal':
                return $this->processPayPal($user, $amount);
            case 'crypto':
                return $this->processCrypto($user, $amount, $walletAddress);
            default:
                throw new \Exception("Méthode de paiement non supportée");
        }
    }

    private function processCardPayment(User $user, float $amount)
    {
        // TODO: Intégrer Stripe ou Paystack ici
        // Ex: $response = $this->stripeService->charge($user, $amount);
        
        return $this->finalizeTransaction($user, $amount, 'Carte');
    }

    private function processMobileMoney(User $user, float $amount)
    {
        // TODO: Intégrer KakiaPay ou FadaPay
        // Ex: $response = $this->mobileMoneyService->process($user, $amount);
        
        return $this->finalizeTransaction($user, $amount, 'Mobile Money');
    }

    private function processPayPal(User $user, float $amount)
    {
        // TODO: Intégrer PayPal Checkout + conversion CoinPayments
        // Ex: $paypalResponse = $this->paypalService->charge($user, $amount);
        
        return $this->finalizeTransaction($user, $amount, 'PayPal');
    }

    private function processCrypto(User $user, float $amount, string $walletAddress)
    {
        if (!$walletAddress) {
            throw new \Exception("Adresse de portefeuille requise pour le paiement en crypto");
        }
        
        // TODO: Vérifier l'adresse, récupérer et convertir les fonds
        // Ex: $cryptoResponse = $this->cryptoService->withdraw($walletAddress, $amount);
        
        return $this->finalizeTransaction($user, $amount, 'Crypto');
    }

    private function finalizeTransaction(User $user, float $amount, string $method)
    {
        // Mise à jour du solde
        $user->setBalance($user->getBalance() + $amount);
        
        // Création d’une transaction
        $transaction = new Transaction();
        $transaction->setUser($user);
        $transaction->setAmount($amount);
        $transaction->setMethod($method);
        $transaction->setDate(new \DateTime());
        
        $this->em->persist($user);
        $this->em->persist($transaction);
        $this->em->flush();
        
        return true;
    }
}