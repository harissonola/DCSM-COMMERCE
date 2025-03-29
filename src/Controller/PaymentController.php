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
    // Taux de change manuels (à modifier selon vos besoins)
    private $exchangeRates = [
        'BTC' => 30000.0, // 1 USD = 1/30000 BTC
        'ETH' => 2000.0,  // 1 USD = 1/2000 ETH
        'USDT' => 1.0,    // 1 USD = 1 USDT
        'TRX' => 4.268,   // 1 USD = 4.268 TRX
        'DOGE' => 130.0,  // 1 USD = 130 DOGE
        'BNB' => 300.0,   // 1 USD = 1/300 BNB
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

        // Récupération des paramètres
        $supportedCurrencies = $this->getSupportedCurrenciesFromCoinPayments();
        $exchangeRate = $this->exchangeRates[$currency];
        $minWithdrawal = $this->getMinWithdrawalAmount($currency);
        dd($exchangeRate, $currency);
        // Validation des données
        if (
            $amountUsd <= 0 ||
            !$this->validateCryptoAddress($recipient, $currency) ||
            !in_array($currency, $supportedCurrencies) ||
            $user->getBalance() < $amountUsd ||
            ($amountUsd / $exchangeRate) < $minWithdrawal
        ) {
            $errors = [];
            if ($amountUsd <= 0) $errors[] = "Montant invalide";
            if (!$this->validateCryptoAddress($recipient, $currency)) $errors[] = "Adresse invalide";
            if (!in_array($currency, $supportedCurrencies)) $errors[] = "Crypto non supportée";
            if ($user->getBalance() < $amountUsd) $errors[] = "Solde insuffisant";
            if (($amountUsd / $exchangeRate) < $minWithdrawal) $errors[] = "Montant trop faible (min " . $minWithdrawal . " " . $currency . ")";

            $this->addFlash('danger', "Erreur : " . implode(', ', $errors));
            return $this->redirectToRoute('app_profile');
        }

        // Conversion USD -> Crypto
        $amountCrypto = $amountUsd / $exchangeRate;

        // Enregistrement de la transaction
        $transaction = new Transactions();
        $transaction
            ->setUser($user)
            ->setAmount(-$amountUsd)
            ->setMethod("Crypto ($currency)")
            ->setStatus('pending')
            ->setCreatedAt(new \DateTimeImmutable());
        $em->persist($transaction);
        $em->flush();

        try {
            // Envoi via CoinPayments
            if (!$this->processCryptoWithdrawal($recipient, $amountCrypto, $currency)) {
                throw new \Exception('Échec du transfert');
            }

            // Mise à jour du solde et statut
            $user->setBalance($user->getBalance() - $amountUsd);
            $transaction->setStatus('completed');

            $em->persist($user);
            $em->persist($transaction);
            $em->flush();

            $this->addFlash(
                'success',
                "Retrait de $amountUsd USD (" .
                    number_format($amountCrypto, 8) .
                    " $currency) effectué avec succès."
            );
        } catch (\Exception $e) {
            $transaction->setStatus('failed');
            $em->persist($transaction);
            $em->flush();
            $this->addFlash(
                'danger',
                "Échec du retrait : " . $e->getMessage() .
                    " (Code erreur : " . $e->getCode() . ")"
            );
        }

        return $this->redirectToRoute('app_profile');
    }

    // --- Gestion des cryptos ---
    private function getSupportedCurrenciesFromCoinPayments(): array
    {
        try {
            $response = $this->coinPaymentsApiCall('rates', ['shortcuts' => 1]);
            return array_keys($response['result']);
        } catch (\Exception $e) {
            return ['BTC', 'ETH', 'USDT', 'TRX', 'DOGE', 'BNB']; // Défaut sécurisé
        }
    }

    private function validateCryptoAddress(string $address, string $currency): bool
    {
        $patterns = [
            'BTC' => '/^([13][a-km-zA-HJ-NP-Z1-9]{25,34}|bc1[a-z0-9]{39,59})$/',
            'ETH' => '/^0x[a-fA-F0-9]{40}$/',
            'USDT' => '/^[a-zA-Z0-9]{25,35}$/',
            'TRX' => '/^[a-zA-Z0-9]{34}$/',
            'DOGE' => '/^D[5-9a-zA-HJ-NP-Z1][^IOl]{32,34}$/',
            'BNB' => '/^[a-zA-Z1-9]{12,}/',
            'LTC' => '/^[a-km-zA-HJ-NP-Z1-9]{26,35}$/',
            'XRP' => '/^[r][a-zA-Z0-9]{27,34}$/',
            'XMR' => '/^[48][0-9ABCMNPQRSTUVWXYZ]{94,105}$/',
        ];

        $pattern = $patterns[$currency] ?? '/^[a-zA-Z0-9]{25,35}$/';
        return preg_match($pattern, $address) === 1;
    }

    private function getMinWithdrawalAmount(string $currency): float
    {
        try {
            $response = $this->coinPaymentsApiCall('get_withdrawal_info', ['currency' => $currency]);
            return (float)($response['result']['min_limit'] ?? 0.0);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    private function processCryptoWithdrawal(
        string $address,
        float $amount,
        string $currency
    ): bool {
        try {
            $params = [
                'amount'      => $amount,
                'currency'    => $currency,
                'address'     => $address,
                'auto_confirm' => 1
            ];

            $response = $this->coinPaymentsApiCall('create_withdrawal', $params);

            if ($response['error'] === 'ok' && $response['result']['status'] === 'confirmed') {
                return true;
            } else {
                throw new \Exception("CoinPayments Error: " . $response['error']);
            }
        } catch (\Exception $e) {
            throw new \Exception("Échec du transfert : " . $e->getMessage(), 500);
        }
    }

    // --- API CoinPayments ---
    private function coinPaymentsApiCall(string $cmd, array $params = []): array
    {
        $privateKey = $_ENV['COINPAYMENTS_API_SECRET'];
        $publicKey = $_ENV['COINPAYMENTS_API_KEY'];

        $params += [
            'version' => 1,
            'cmd'     => $cmd,
            'key'     => $publicKey,
            'format'  => 'json'
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
            throw new \Exception("cURL Error: " . curl_error($ch));
        }

        $result = json_decode($data, true);
        if ($result['error'] !== 'ok') {
            throw new \Exception("CoinPayments Error: " . $result['error']);
        }

        return $result;
    }

    // --- Dépôts ---
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
            case 'carte':
                if (!$this->processCardPayment($user, $amount)) {
                    $this->addFlash('danger', 'Erreur de paiement par carte.');
                    return $this->redirectToRoute('app_profile');
                }
                break;
            case 'mobilemoney':
                if (!$this->processMobileMoney($user, $amount)) {
                    $this->addFlash('danger', 'Erreur avec Mobile Money.');
                    return $this->redirectToRoute('app_profile');
                }
                break;
            case 'paypal':
                return $this->redirectToRoute('app_paypal_redirect', ['amount' => $amount]);
            case 'crypto':
                return $this->redirectToRoute('app_crypto_redirect', ['amount' => $amount]);
            default:
                $this->addFlash('danger', 'Méthode de paiement invalide.');
                return $this->redirectToRoute('app_profile');
        }

        $this->addFlash('info', 'Traitement du dépôt en cours.');
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/crypto/redirect', name: 'app_crypto_redirect', methods: ['GET', 'POST'])]
    public function cryptoRedirect(Request $request): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant doit être supérieur à zéro.');
            return $this->redirectToRoute('app_profile');
        }

        if ($request->isMethod('POST')) {
            $cryptoType = $request->request->get('cryptoType');
            $walletAddress = $request->request->get('walletAddress');

            if (!$cryptoType || !$walletAddress) {
                $this->addFlash('danger', 'Le type de crypto et l\'adresse sont requis.');
                return $this->redirectToRoute('app_profile');
            }

            $user = $this->getUser();
            if (!$user) {
                return $this->redirectToRoute('app_login');
            }

            $params = [
                'amount'      => $amount,
                'currency1'   => 'USDT',
                'currency2'   => $cryptoType,
                'buyer_email' => $user->getEmail(),
                'item_name'   => 'Dépôt sur le site',
                'ipn_url'     => $this->generateUrl('coinpayments_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ];

            try {
                $response = $this->coinPaymentsApiCall('create_transaction', $params);
                $paymentUrl = $response['result']['checkout_url'];
                return $this->redirect($paymentUrl);
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Erreur CoinPayments: ' . $e->getMessage());
                return $this->redirectToRoute('app_profile');
            }
        }

        return $this->render('payment/crypto_deposit.html.twig', ['amount' => $amount]);
    }

    #[Route('/coinpayments/ipn', name: 'coinpayments_ipn', methods: ['POST'])]
    public function coinpaymentsIpn(Request $request, EntityManagerInterface $em): Response
    {
        $payload = $request->getContent();
        $hmacHeader = $request->headers->get('HMAC');
        $expectedHmac = hash_hmac('sha512', $payload, $_ENV['COINPAYMENTS_API_SECRET']);

        if ($expectedHmac !== $hmacHeader) {
            return new Response('Invalid HMAC', 400);
        }

        $data = json_decode($payload, true);
        if (!$data) {
            return new Response('Invalid JSON', 400);
        }

        if (isset($data['status']) && (int)$data['status'] === 100) {
            $txnId = $data['txn_id'] ?? null;
            if (!$txnId) {
                return new Response('Transaction ID manquant', 400);
            }

            $existingTransaction = $em->getRepository(Transactions::class)->findOneBy(['externalId' => $txnId]);
            if ($existingTransaction) {
                return new Response('Transaction déjà traitée', 200);
            }

            $buyerEmail = $data['buyer_email'] ?? null;
            if (!$buyerEmail) {
                return new Response('Email de l\'acheteur manquant', 400);
            }

            $user = $em->getRepository(User::class)->findOneBy(['email' => $buyerEmail]);
            if (!$user) {
                return new Response('Utilisateur non trouvé', 400);
            }

            $transaction = new Transactions();
            $transaction
                ->setUser($user)
                ->setAmount((float)$data['amount1'])
                ->setMethod('crypto')
                ->setCreatedAt(new \DateTimeImmutable())
                ->setExternalId($txnId);

            $em->persist($transaction);
            $user->setBalance($user->getBalance() + (float)$data['amount1']);
            $em->persist($user);
            $em->flush();

            return new Response('OK', 200);
        }

        return new Response('IPN reçu', 200);
    }

    // --- PayPal ---
    #[Route('/paypal/redirect', name: 'app_paypal_redirect', methods: ['GET'])]
    public function paypalRedirect(Request $request): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant doit être supérieur à zéro.');
            return $this->redirectToRoute('app_profile');
        }

        $returnUrl = $this->generateUrl('paypal_return', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $cancelUrl = $this->generateUrl('paypal_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $client = $this->getPayPalClient();
        $paypalRequest = new OrdersCreateRequest();
        $paypalRequest->prefer('return=representation');
        $paypalRequest->body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => 'USD',
                    'value' => number_format($amount, 2, '.', '')
                ],
                'description' => 'Dépôt sur le site'
            ]],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'brand_name' => $this->getParameter('app.site_name') ?? 'Votre Site',
                'user_action' => 'PAY_NOW',
            ]
        ];

        try {
            $response = $client->execute($paypalRequest);
            if ($response->statusCode !== 201) {
                throw new \Exception('Échec de la création de la commande PayPal');
            }

            $request->getSession()->set('paypal_order_id', $response->result->id);

            foreach ($response->result->links as $link) {
                if ($link->rel === 'approve') {
                    return $this->redirect($link->href);
                }
            }

            throw new \Exception('Lien d\'approbation PayPal non trouvé');
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur PayPal: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/paypal/return', name: 'paypal_return', methods: ['GET'])]
    public function paypalReturn(Request $request, EntityManagerInterface $em): Response
    {
        $orderId = $request->query->get('token') ?? $request->getSession()->get('paypal_order_id');
        if (!$orderId) {
            $this->addFlash('danger', 'Informations de commande PayPal manquantes.');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $captureRequest = new OrdersCaptureRequest($orderId);
        $client = $this->getPayPalClient();

        try {
            $response = $client->execute($captureRequest);

            if ($response->result->status !== 'COMPLETED') {
                throw new \Exception('Paiement PayPal non complété');
            }

            $amount = (float)$response->result->purchase_units[0]->amount->value;

            $transaction = new Transactions();
            $transaction
                ->setUser($user)
                ->setAmount($amount)
                ->setMethod('paypal')
                ->setStatus('completed')
                ->setCreatedAt(new \DateTimeImmutable());

            $user->setBalance($user->getBalance() + $amount);

            $em->persist($transaction);
            $em->persist($user);
            $em->flush();

            $this->addFlash('success', "Dépôt de $amount USD effectué via PayPal !");
            return $this->redirectToRoute('app_profile');
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur PayPal: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/paypal/cancel', name: 'paypal_cancel', methods: ['GET'])]
    public function paypalCancel(): Response
    {
        $this->addFlash('warning', 'Paiement PayPal annulé.');
        return $this->redirectToRoute('app_profile');
    }

    private function getPayPalClient(): PayPalHttpClient
    {
        $clientId = $_ENV["PAYPAL_CLIENT_ID"];
        $clientSecret = $_ENV["PAYPAL_CLIENT_SECRET"];
        $isProduction = $_ENV["APP_ENV"] === 'prod';
        $environment = $isProduction
            ? new ProductionEnvironment($clientId, $clientSecret)
            : new SandboxEnvironment($clientId, $clientSecret);

        return new PayPalHttpClient($environment);
    }

    // --- Méthodes non implémentées ---
    private function processCardPayment(User $user, float $amount): bool
    {
        return false;
    }

    private function processMobileMoney(User $user, float $amount): bool
    {
        return false;
    }
}
