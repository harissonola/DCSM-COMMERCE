<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Transactions;
use App\Entity\User;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;

class PaymentController extends AbstractController
{
    #[Route('/withdraw', name: 'app_withdraw', methods: ['POST'])]
    public function withdraw(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $amountUsd = (float)$request->request->get('amount');
        $currency = strtoupper(trim($request->request->get('currency')));
        $address = trim($request->request->get('recipient'));

        // Validation de base
        if ($amountUsd <= 0 || $amountUsd > $user->getBalance()) {
            $this->addFlash('danger', 'Montant invalide ou solde insuffisant');
            return $this->redirectToRoute('app_profile');
        }

        if (empty($address)) {
            $this->addFlash('danger', 'Adresse de portefeuille requise');
            return $this->redirectToRoute('app_profile');
        }

        // Création de la transaction en statut "pending"
        $transaction = (new Transactions())
            ->setUser($user)
            ->setType('withdrawal')
            ->setAmount($amountUsd)
            ->setMethod("Crypto ($currency)")
            ->setStatus('pending')
            ->setCreatedAt(new \DateTimeImmutable());

        $em->persist($transaction);
        $em->flush();

        try {
            // Récupération des taux de change depuis CoinPayments
            $rates = $this->getExchangeRates();
            if (!isset($rates[$currency])) {
                throw new \Exception("Devise non supportée");
            }

            // Conversion USD vers la crypto choisie
            $rate = (float)$rates[$currency]['rate_usd'];
            $amountCrypto = $amountUsd / $rate;

            // Envoi de la demande de retrait à CoinPayments
            $params = [
                'amount' => $amountCrypto, // Montant dans la crypto choisie
                'currency' => $currency,   // Devise de retrait
                'currency2' => 'USD',      // Devise source (pour conversion)
                'address' => $address,
                'auto_confirm' => 1,
                'ipn_url' => $this->generateUrl('coinpayments_withdrawal_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'custom' => $transaction->getId()
            ];

            $response = $this->coinPaymentsApiCall('create_withdrawal', $params);

            if ($response['error'] !== 'ok') {
                throw new \Exception($response['error'] ?? 'Erreur inconnue de CoinPayments');
            }

            $this->addFlash('success', sprintf(
                'Demande de retrait de %.2f USD (environ %f %s) envoyée. Le traitement peut prendre quelques minutes.',
                $amountUsd,
                $amountCrypto,
                $currency
            ));
        } catch (\Exception $e) {
            $transaction->setStatus('failed');
            $em->flush();
            $this->addFlash('danger', "Échec de la demande de retrait : " . $e->getMessage());
        }

        return $this->redirectToRoute('app_profile');
    }

    private function getExchangeRates(): array
    {
        $response = $this->coinPaymentsApiCall('rates');

        if ($response['error'] !== 'ok') {
            throw new \Exception("Erreur lors de la récupération des taux de change");
        }

        return $response['result'];
    }

    #[Route('/deposit', name: 'app_deposit', methods: ['POST'])]
    public function deposit(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $amount = (float)$request->request->get('amount');
        $paymentMethod = $request->request->get('paymentMethod');
        $cryptoType = strtoupper(trim($request->request->get('cryptoType')));

        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant doit être supérieur à zéro.');
            return $this->redirectToRoute('app_profile');
        }

        switch ($paymentMethod) {
            case 'paypal':
                return $this->redirectToRoute('app_paypal_redirect', ['amount' => $amount]);
            case 'crypto':
                return $this->processCryptoDeposit($amount, $cryptoType, $user);
            default:
                $this->addFlash('danger', 'Méthode de paiement invalide.');
                return $this->redirectToRoute('app_profile');
        }
    }

    private function processCryptoDeposit(float $amount, string $cryptoType, User $user): Response
    {
        try {
            $params = [
                'amount' => $amount,
                'currency1' => 'USD',
                'currency2' => $cryptoType,
                'buyer_email' => $user->getEmail(),
                'item_name' => 'Dépôt sur ' . $this->getParameter('app.site_name'),
                'ipn_url' => $this->generateUrl('coinpayments_deposit_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'custom' => $user->getId()
            ];

            $response = $this->coinPaymentsApiCall('create_transaction', $params);

            if ($response['error'] !== 'ok') {
                throw new \Exception($response['error'] ?? 'Erreur inconnue de CoinPayments');
            }

            return $this->redirect($response['result']['checkout_url']);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/coinpayments/deposit-ipn', name: 'coinpayments_deposit_ipn', methods: ['POST'])]
    public function coinpaymentsDepositIpn(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->validateCoinpaymentsIpn($request)) {
            return new Response('HMAC invalide', 401);
        }

        $data = json_decode($request->getContent(), true);
        if ($data === null) {
            return new Response('Données invalides', 400);
        }

        // Traitement seulement pour les transactions complètes (status >= 100)
        if (($data['status'] ?? 0) >= 100) {
            $userId = $data['custom'] ?? null;
            $amount = (float)($data['amount1'] ?? 0); // Montant en USD
            $txnId = $data['txn_id'] ?? null;

            if (!$userId || $amount <= 0 || !$txnId) {
                return new Response('Données manquantes', 400);
            }

            // Vérifier si la transaction existe déjà
            if ($em->getRepository(Transactions::class)->findOneBy(['externalId' => $txnId])) {
                return new Response('Transaction déjà traitée', 200);
            }

            $user = $em->getRepository(User::class)->find($userId);
            if (!$user) {
                return new Response('Utilisateur non trouvé', 404);
            }

            // Créer la transaction
            $transaction = (new Transactions())
                ->setUser($user)
                ->setAmount($amount)
                ->setMethod('crypto_deposit')
                ->setStatus('completed')
                ->setExternalId($txnId)
                ->setCreatedAt(new \DateTimeImmutable());

            $user->setBalance($user->getBalance() + $amount);

            $em->persist($transaction);
            $em->persist($user);
            $em->flush();
        }

        return new Response('OK', 200);
    }

    #[Route('/coinpayments/withdrawal-ipn', name: 'coinpayments_withdrawal_ipn', methods: ['POST'])]
    public function coinpaymentsWithdrawalIpn(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->validateCoinpaymentsIpn($request)) {
            return new Response('HMAC invalide', 401);
        }

        $data = json_decode($request->getContent(), true);
        if ($data === null) {
            return new Response('Données invalides', 400);
        }

        $transactionId = $data['custom'] ?? null;
        $status = (int)($data['status'] ?? 0);
        $withdrawalId = $data['id'] ?? null;

        if (!$transactionId || !$withdrawalId) {
            return new Response('Données manquantes', 400);
        }

        // Trouver la transaction dans notre base
        $transaction = $em->getRepository(Transactions::class)->find($transactionId);
        if (!$transaction) {
            return new Response('Transaction non trouvée', 404);
        }

        $user = $transaction->getUser();

        // Statut >= 100 signifie succès
        if ($status >= 100) {
            $transaction->setStatus('completed');
            $user->setBalance($user->getBalance() - $transaction->getAmount());
        }
        // Statut < 0 signifie échec
        elseif ($status < 0) {
            $transaction->setStatus('failed');
        }

        $transaction->setExternalId($withdrawalId);
        $em->flush();

        return new Response('OK', 200);
    }

    #[Route('/paypal/redirect', name: 'app_paypal_redirect')]
    public function paypalRedirect(Request $request): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Montant invalide');
            return $this->redirectToRoute('app_profile');
        }

        $client = $this->getPayPalClient();
        $paypalRequest = new OrdersCreateRequest();
        $paypalRequest->prefer('return=representation');
        $paypalRequest->body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => 'USD',
                    'value' => number_format($amount, 2, '.', '')
                ]
            ]],
            'application_context' => [
                'return_url' => $this->generateUrl('paypal_return', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'cancel_url' => $this->generateUrl('paypal_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'brand_name' => $this->getParameter('app.site_name'),
                'user_action' => 'PAY_NOW',
            ]
        ];

        try {
            $response = $client->execute($paypalRequest);
            $approveUrl = null;

            foreach ($response->result->links as $link) {
                if ($link->rel === 'approve') {
                    $approveUrl = $link->href;
                    break;
                }
            }

            if (!$approveUrl) {
                throw new \Exception('URL PayPal introuvable');
            }

            // Stocker l'ID de commande et le montant en session
            $request->getSession()->set('paypal_order_id', $response->result->id);
            $request->getSession()->set('paypal_amount', $amount);

            return $this->redirect($approveUrl);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur PayPal: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/paypal/return', name: 'paypal_return')]
    public function paypalReturn(Request $request, EntityManagerInterface $em): Response
    {
        $orderId = $request->query->get('token') ?? $request->getSession()->get('paypal_order_id');
        $amount = $request->getSession()->get('paypal_amount');

        if (!$orderId || !$amount) {
            $this->addFlash('danger', 'Commande PayPal introuvable');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        $client = $this->getPayPalClient();
        $captureRequest = new OrdersCaptureRequest($orderId);

        try {
            $response = $client->execute($captureRequest);

            if ($response->result->status !== 'COMPLETED') {
                throw new \Exception('Paiement non complété');
            }

            $transaction = (new Transactions())
                ->setUser($user)
                ->setAmount($amount)
                ->setMethod('paypal')
                ->setStatus('completed')
                ->setExternalId($orderId)
                ->setCreatedAt(new \DateTimeImmutable());

            $user->setBalance($user->getBalance() + $amount);

            $em->persist($transaction);
            $em->persist($user);
            $em->flush();

            // Nettoyer la session
            $request->getSession()->remove('paypal_order_id');
            $request->getSession()->remove('paypal_amount');

            $this->addFlash('success', sprintf('Dépôt de %.2f USD effectué', $amount));
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/paypal/cancel', name: 'paypal_cancel')]
    public function paypalCancel(Request $request): Response
    {
        $request->getSession()->remove('paypal_order_id');
        $request->getSession()->remove('paypal_amount');
        $this->addFlash('warning', 'Paiement annulé');
        return $this->redirectToRoute('app_profile');
    }

    private function validateCoinpaymentsIpn(Request $request): bool
    {
        $hmacHeader = $request->headers->get('HMAC');
        $hmacCalculated = hash_hmac('sha512', $request->getContent(), $_ENV['COINPAYMENTS_API_SECRET']);

        return $hmacHeader === $hmacCalculated;
    }

    private function coinPaymentsApiCall(string $cmd, array $params = []): array
    {
        $privateKey = $_ENV['COINPAYMENTS_API_SECRET'];
        $publicKey = $_ENV['COINPAYMENTS_API_KEY'];

        $params += [
            'version' => 1,
            'cmd' => $cmd,
            'key' => $publicKey,
            'format' => 'json'
        ];

        $postData = http_build_query($params, '', '&');
        $hmac = hash_hmac('sha512', $postData, $privateKey);

        $ch = curl_init('https://www.coinpayments.net/api.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ["hmac: $hmac"],
            CURLOPT_POSTFIELDS => $postData
        ]);

        $data = curl_exec($ch);
        if ($data === false) {
            throw new \Exception("Erreur cURL: " . curl_error($ch));
        }

        $result = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Réponse API invalide");
        }

        if (!isset($result['error']) || $result['error'] !== 'ok') {
            throw new \Exception($result['error'] ?? 'Erreur inconnue');
        }

        return $result;
    }

    private function getPayPalClient(): PayPalHttpClient
    {
        $clientId = $_ENV['PAYPAL_CLIENT_ID'];
        $clientSecret = $_ENV['PAYPAL_CLIENT_SECRET'];

        $environment = $_ENV['APP_ENV'] === 'prod'
            ? new ProductionEnvironment($clientId, $clientSecret)
            : new SandboxEnvironment($clientId, $clientSecret);

        return new PayPalHttpClient($environment);
    }
}