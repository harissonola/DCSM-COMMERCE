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
    // Taux de change pour les principales cryptos
    private $exchangeRates = [
        'BTC' => 82209.33,
        'ETH' => 1815.33,
        'USDT' => 1.00,
        'BUSD' => 1.00,
        'TRX' => 0.23,
        'DOGE' => 0.17,
        'BNB' => 602.90,
        'LTC' => 85.21,
        'XRP' => 0.52,
    ];

    #[Route('/withdraw', name: 'app_withdraw', methods: ['POST'])]
    public function withdraw(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $amountUsd = (float)$request->request->get('amount');
        $recipient = trim($request->request->get('recipient'));
        $currency = strtoupper(trim($request->request->get('currency')));

        // Validation des données
        $errors = $this->validateWithdrawal($user, $amountUsd, $currency, $recipient);
        if (!empty($errors)) {
            $this->addFlash('danger', "Erreur : " . implode(', ', $errors));
            return $this->redirectToRoute('app_profile');
        }

        // Conversion USD -> Crypto
        $amountCrypto = $amountUsd / $this->exchangeRates[$currency];

        // Enregistrement de la transaction
        $transaction = (new Transactions())
            ->setUser($user)
            ->setType("withdrawal")
            ->setAmount($amountUsd)
            ->setMethod("Crypto ($currency)")
            ->setStatus('pending')
            ->setCreatedAt(new \DateTimeImmutable());

        $em->persist($transaction);
        $em->flush();

        try {
            $this->processCryptoWithdrawal($recipient, $amountCrypto, $currency);
            
            $user->setBalance($user->getBalance() - $amountUsd);
            $transaction->setStatus('completed');
            
            $em->flush();
            
            $this->addFlash(
                'success',
                sprintf("Retrait de %.2f USD (%f %s) effectué avec succès.", $amountUsd, $amountCrypto, $currency)
            );
        } catch (\Exception $e) {
            $transaction->setStatus('failed');
            $em->flush();
            
            $this->addFlash('danger', "Échec du retrait : " . $e->getMessage());
        }

        return $this->redirectToRoute('app_profile');
    }

    private function validateWithdrawal(User $user, float $amount, string $currency, string $address): array
    {
        $errors = [];
        
        if ($amount <= 0) {
            $errors[] = "Montant invalide";
        }
        
        if (!array_key_exists($currency, $this->exchangeRates)) {
            $errors[] = "Devise non supportée";
        }
        
        if ($user->getBalance() < $amount) {
            $errors[] = "Solde insuffisant";
        }
        
        if (!$this->validateCryptoAddress($address, $currency)) {
            $errors[] = "Adresse $currency invalide";
        }
        
        return $errors;
    }

    private function validateCryptoAddress(string $address, string $currency): bool
    {
        if (empty($address)) {
            return false;
        }

        $patterns = [
            'BTC' => '/^([13][a-km-zA-HJ-NP-Z1-9]{25,34}|bc1[a-z0-9]{39,59})$/',
            'ETH' => '/^0x[a-fA-F0-9]{40}$/',
            'USDT' => '/^0x[a-fA-F0-9]{40}$/',
            'BUSD' => '/^0x[a-fA-F0-9]{40}$/',
            'TRX' => '/^T[a-zA-Z0-9]{33}$/',
            'DOGE' => '/^D[5-9a-zA-HJ-NP-Z1][^IOl]{32,34}$/',
            'BNB' => '/^bnb[a-z0-9]{38}$/i',
            'LTC' => '/^[LM3][a-km-zA-HJ-NP-Z1-9]{26,33}$/',
            'XRP' => '/^r[0-9a-zA-Z]{24,34}$/',
        ];

        return isset($patterns[$currency]) 
            ? preg_match($patterns[$currency], $address) === 1
            : strlen($address) >= 20; // Validation minimaliste pour les autres cryptos
    }

    private function processCryptoWithdrawal(string $address, float $amount, string $currency): void
    {
        $params = [
            'amount' => $amount,
            'currency' => $currency,
            'address' => $address,
            'auto_confirm' => 1
        ];

        $response = $this->coinPaymentsApiCall('create_withdrawal', $params);

        if ($response['error'] !== 'ok') {
            throw new \Exception($response['error'] ?? 'Erreur inconnue de CoinPayments');
        }
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

    #[Route('/deposit', name: 'app_deposit', methods: ['POST'])]
    public function deposit(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $amount = (float)$request->request->get('amount');
        $paymentMethod = $request->request->get('paymentMethod');

        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant doit être supérieur à zéro.');
            return $this->redirectToRoute('app_profile');
        }

        switch ($paymentMethod) {
            case 'paypal':
                return $this->redirectToRoute('app_paypal_redirect', ['amount' => $amount]);
            case 'crypto':
                return $this->redirectToRoute('app_crypto_redirect', ['amount' => $amount]);
            default:
                $this->addFlash('danger', 'Méthode de paiement invalide.');
                return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/crypto/redirect', name: 'app_crypto_redirect')]
    public function cryptoRedirect(Request $request): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Montant invalide');
            return $this->redirectToRoute('app_profile');
        }

        if ($request->isMethod('POST')) {
            $cryptoType = strtoupper(trim($request->request->get('cryptoType')));
            
            if (!array_key_exists($cryptoType, $this->exchangeRates)) {
                $this->addFlash('danger', 'Type de crypto non supporté');
                return $this->redirectToRoute('app_profile');
            }

            $user = $this->getUser();
            $params = [
                'amount' => $amount,
                'currency1' => 'USD',
                'currency2' => $cryptoType,
                'buyer_email' => $user->getEmail(),
                'item_name' => 'Dépôt',
                'ipn_url' => $this->generateUrl('coinpayments_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ];

            try {
                $response = $this->coinPaymentsApiCall('create_transaction', $params);
                return $this->redirect($response['result']['checkout_url']);
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Erreur: ' . $e->getMessage());
                return $this->redirectToRoute('app_profile');
            }
        }

        return $this->render('payment/crypto_deposit.html.twig', [
            'amount' => $amount,
            'currencies' => array_keys($this->exchangeRates)
        ]);
    }

    #[Route('/coinpayments/ipn', name: 'coinpayments_ipn', methods: ['POST'])]
    public function coinpaymentsIpn(Request $request, EntityManagerInterface $em): Response
    {
        // Vérification HMAC
        $hmacHeader = $request->headers->get('HMAC');
        $hmacCalculated = hash_hmac('sha512', $request->getContent(), $_ENV['COINPAYMENTS_API_SECRET']);
        
        if ($hmacHeader !== $hmacCalculated) {
            return new Response('HMAC invalide', 401);
        }

        $data = json_decode($request->getContent(), true);
        if ($data === null) {
            return new Response('Données invalides', 400);
        }

        // Traitement seulement pour les transactions complètes
        if (($data['status'] ?? 0) >= 100) {
            $txnId = $data['txn_id'] ?? null;
            $amount = (float)($data['amount1'] ?? 0);
            $email = $data['buyer_email'] ?? null;

            if (!$txnId || $amount <= 0 || !$email) {
                return new Response('Données manquantes', 400);
            }

            // Vérifier si la transaction existe déjà
            if ($em->getRepository(Transactions::class)->findOneBy(['externalId' => $txnId])) {
                return new Response('Transaction déjà traitée', 200);
            }

            $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if (!$user) {
                return new Response('Utilisateur non trouvé', 404);
            }

            // Créer la transaction
            $transaction = (new Transactions())
                ->setUser($user)
                ->setAmount($amount)
                ->setMethod('crypto')
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

    #[Route('/paypal/redirect', name: 'app_paypal_redirect')]
    public function paypalRedirect(Request $request): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Montant invalide');
            return $this->redirectToRoute('app_profile');
        }

        $client = $this->getPayPalClient();
        $request = new OrdersCreateRequest();
        $request->prefer('return=representation');
        $request->body = [
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
            $response = $client->execute($request);
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

            $request->getSession()->set('paypal_order_id', $response->result->id);
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
        if (!$orderId) {
            $this->addFlash('danger', 'Commande PayPal introuvable');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        $client = $this->getPayPalClient();
        $request = new OrdersCaptureRequest($orderId);

        try {
            $response = $client->execute($request);
            
            if ($response->result->status !== 'COMPLETED') {
                throw new \Exception('Paiement non complété');
            }

            $amount = (float)$response->result->purchase_units[0]->amount->value;
            
            $transaction = (new Transactions())
                ->setUser($user)
                ->setAmount($amount)
                ->setMethod('paypal')
                ->setStatus('completed')
                ->setCreatedAt(new \DateTimeImmutable());

            $user->setBalance($user->getBalance() + $amount);

            $em->persist($transaction);
            $em->persist($user);
            $em->flush();

            $this->addFlash('success', sprintf('Dépôt de %.2f USD effectué', $amount));
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/paypal/cancel', name: 'paypal_cancel')]
    public function paypalCancel(): Response
    {
        $this->addFlash('warning', 'Paiement annulé');
        return $this->redirectToRoute('app_profile');
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