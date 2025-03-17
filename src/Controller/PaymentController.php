<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Transactions;
use App\Entity\User;

class PaymentController extends AbstractController
{
    #[Route('/deposit', name: 'app_deposit', methods: ['POST'])]
    public function deposit(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $amount = (float) $request->request->get('amount');
        $paymentMethod = $request->request->get('paymentMethod');

        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant doit être supérieur à zéro.');
            return $this->redirectToRoute('app_profile');
        }

        switch ($paymentMethod) {
            case 'carte':
                // Décaisser les fonds via l'API de paiement par carte (ex: Stripe, Paystack, etc.)
                $transferStatus = $this->processCardPayment($user, $amount);
                if (!$transferStatus) {
                    $this->addFlash('danger', 'Erreur lors du transfert depuis la carte bancaire.');
                    return $this->redirectToRoute('app_profile');
                }
                break;

            case 'mobilemoney':
                // Décaisser les fonds via l'API Mobile Money (ex: KakiaPay, FadaPay)
                $transferStatus = $this->processMobileMoney($user, $amount);
                if (!$transferStatus) {
                    $this->addFlash('danger', 'Erreur lors du transfert depuis Mobile Money.');
                    return $this->redirectToRoute('app_profile');
                }
                break;

            case 'paypal':
                // Décaisser les fonds via l'API PayPal
                $transferStatus = $this->processPayPal($user, $amount);
                if (!$transferStatus) {
                    $this->addFlash('danger', 'Erreur lors du transfert depuis PayPal.');
                    return $this->redirectToRoute('app_profile');
                }
                break;

            case 'crypto':
                // Récupération des données spécifiques à la crypto depuis le formulaire dynamique
                $cryptoType = $request->request->get('cryptoType');
                $walletAddress = $request->request->get('walletAddress');
                if (empty($cryptoType) || empty($walletAddress)) {
                    $this->addFlash('danger', 'Veuillez renseigner le type de crypto et l\'adresse du portefeuille.');
                    return $this->redirectToRoute('app_profile');
                }
                // Conversion du montant en TRX (car le portefeuille DCSM-COMMERCE fonctionne en TRX)
                $convertedAmount = $this->convertToTRX($amount, $cryptoType);
                // Adresse TRX de DCSM-COMMERCE (remplacez par l'adresse réelle)
                $commerceWalletAddress = 'TLQMEec1F5zJuHXsgKWfbUqEHXWj9p5KkV';
                // Exécuter le transfert effectif via CoinPayments
                $transferStatus = $this->executeCryptoTransfer($walletAddress, $commerceWalletAddress, $convertedAmount, $cryptoType);
                if (!$transferStatus) {
                    $this->addFlash('danger', 'Une erreur est survenue lors du transfert.');
                    return $this->redirectToRoute('app_profile');
                }
                break;

            default:
                $this->addFlash('danger', 'Méthode de paiement invalide.');
                return $this->redirectToRoute('app_profile');
        }

        // Enregistrement de la transaction et mise à jour du solde
        $transaction = new Transactions();
        $transaction->setUser($user);
        $transaction->setAmount($amount);
        $transaction->setMethod($paymentMethod);
        $transaction->setCreatedAt(new \DateTimeImmutable());
        $em->persist($transaction);

        // Mise à jour du solde uniquement après confirmation du transfert effectif
        $user->setBalance($user->getBalance() + $amount);
        $em->flush();

        $this->addFlash('success', 'Dépôt réussi !');
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/withdraw', name: 'app_withdraw', methods: ['POST'])]
    public function withdraw(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        $amount = (float) $request->request->get('amount');
        $recipient = $request->request->get('recipient');

        if ($amount <= 0 || $amount > $user->getBalance()) {
            $this->addFlash('danger', 'Montant invalide ou solde insuffisant.');
            return $this->redirectToRoute('app_profile');
        }

        // Implémentation de la logique de retrait selon la méthode choisie
        $this->processWithdrawal($user, $amount, $recipient);
        
        $transaction = new Transactions();
        $transaction->setUser($user);
        $transaction->setAmount(-$amount);
        $transaction->setMethod('withdraw');
        $transaction->setCreatedAt(new \DateTimeImmutable());
        $em->persist($transaction);
        $user->setBalance($user->getBalance() - $amount);
        $em->flush();

        $this->addFlash('success', 'Retrait effectué avec succès !');
        return $this->redirectToRoute('app_profile');
    }

    private function processCardPayment(User $user, float $amount): bool
    {
        // Implémenter l'appel réel à l'API de paiement par carte (ex: Stripe, Paystack)
        return true;
    }

    private function processMobileMoney(User $user, float $amount): bool
    {
        // Implémenter l'appel réel à l'API Mobile Money (ex: KakiaPay, FadaPay)
        return true;
    }

    private function processPayPal(User $user, float $amount): bool
    {
        // Implémenter l'appel réel à l'API PayPal
        return true;
    }

    /**
     * Conversion du montant depuis la crypto source en TRX.
     *
     * @param float  $amount       Montant en devise source
     * @param string $fromCurrency Type de crypto source (ex: BTC, USDT, etc.)
     * @return float Montant converti en TRX
     */
    private function convertToTRX(float $amount, string $fromCurrency): float
    {
        // Implémentez ici la logique de conversion, par exemple en appelant une API de taux de change.
        // Pour cet exemple, nous simulons avec un taux de conversion fixe.
        $conversionRate = 10; // Exemple : 1 unité de la crypto source équivaut à 10 TRX
        return $amount * $conversionRate;
    }

    /**
     * Exécute le transfert effectif de crypto depuis le portefeuille de l'utilisateur vers le portefeuille TRX de DCSM-COMMERCE via CoinPayments.
     *
     * @param string $sourceWallet       Adresse du portefeuille de l'utilisateur
     * @param string $destinationWallet  Adresse TRX de DCSM-COMMERCE
     * @param float  $amountTRX          Montant à transférer en TRX
     * @param string $cryptoType         Type de crypto source
     * @return mixed Retourne l'ID de la transaction (ou hash) en cas de succès, false sinon
     */
    private function executeCryptoTransfer(string $sourceWallet, string $destinationWallet, float $amountTRX, string $cryptoType)
    {
        // Clés CoinPayments fournies (à stocker en sécurité dans un .env en production)
        $publicKey = 'bad30fb4f363200ecf18598cc672343896ea47c9ba82d0a7a399fced1c9788fb';
        $privateKey = '81E53C880c1568bc8BE2c05c8F6fB75b573C6e239654b6E9B60352eBC586bBc2';
        
        $url = 'https://www.coinpayments.net/api.php';
        
        // Préparer les paramètres pour créer une transaction
        $params = [
            'version'      => 1,
            'cmd'          => 'create_transaction',
            'key'          => $publicKey,
            'format'       => 'json',
            'amount'       => $amountTRX,
            'currency1'    => $cryptoType,       // Devise source (ex: BTC, USDT, etc.)
            'currency2'    => 'TRX',             // Devise cible
            'address'      => $destinationWallet, // Portefeuille TRX de DCSM-COMMERCE
            'auto_confirm' => 1,                 // Confirmation automatique si possible
        ];
        
        // Transformation des paramètres en chaîne de requête
        $post_data = http_build_query($params, '', '&');
        
        // Générer la signature HMAC
        $hmac = hash_hmac('sha512', $post_data, $privateKey);
        
        // Initialiser cURL pour envoyer la requête
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['HMAC: ' . $hmac]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        
        if ($response === false) {
            return false;
        }
        curl_close($ch);
        
        $result = json_decode($response, true);
        var_dump($result); die; // Debug ici
        if ($result['error'] === 'ok') {
            // Retourne l'ID de transaction pour suivi ou true en cas de succès
            return $result['result']['txn_id'] ?? true;
        }
        
        
        return false;
    }

    private function processWithdrawal(User $user, float $amount, string $recipient)
    {
        // Implémenter ici la logique de retrait selon la méthode choisie (virement bancaire, transfert crypto, etc.)
    }
}