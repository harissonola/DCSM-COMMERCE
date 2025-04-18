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
    /**
     * Calcule les frais de retrait en fonction du montant demandé
     */
    private function calculateWithdrawalFees(float $amount): float
    {
        // Structure tarifaire pour les retraits
        if ($amount <= 20) {
            $feePercentage = 0.05; // 5% pour $2-$20
        } elseif ($amount <= 100) {
            $feePercentage = 0.03; // 3% pour $20.01-$100
        } elseif ($amount <= 500) {
            $feePercentage = 0.02; // 2% pour $100.01-$500
        } else {
            $feePercentage = 0.01; // 1% pour $500.01+
        }

        $fee = $amount * $feePercentage;

        // Frais minimum de $1
        return max($fee, 1.0);
    }

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
        if ($amountUsd <= 0) {
            $this->addFlash('danger', 'Le montant demandé est invalide.');
            return $this->redirectToRoute('app_profile');
        }
        if ($amountUsd < 2) {
            $this->addFlash('danger', 'Le montant de retrait doit être d\'au moins 2 USD.');
            return $this->redirectToRoute('app_profile');
        }
        if (empty($address)) {
            $this->addFlash('danger', 'Veuillez fournir une adresse de portefeuille valide.');
            return $this->redirectToRoute('app_profile');
        }

        // Calcul des frais de retrait
        $fees = $this->calculateWithdrawalFees($amountUsd);
        $totalAmount = $amountUsd + $fees;

        // Vérifier si l'utilisateur a suffisamment de fonds (montant + frais)
        if ($totalAmount > $user->getBalance()) {
            $this->addFlash('danger', sprintf(
                'Solde insuffisant. Le retrait de %.2f USD nécessite %.2f USD de frais, soit un total de %.2f USD.',
                $amountUsd,
                $fees,
                $totalAmount
            ));
            return $this->redirectToRoute('app_profile');
        }

        // Création de la transaction en statut "pending"
        $transaction = (new Transactions())
            ->setUser($user)
            ->setType('withdrawal')
            ->setAmount($amountUsd)
            ->setFees($fees)  // Stockage des frais dans un champ 'fees'
            ->setMethod("Crypto ($currency)")
            ->setStatus('pending')
            ->setCreatedAt(new \DateTimeImmutable());
        $em->persist($transaction);

        // Déduire immédiatement le montant total (montant + frais) du solde de l'utilisateur
        $user->setBalance($user->getBalance() - $totalAmount);
        $em->persist($user);
        $em->flush();

        try {
            // Envoi de la demande de retrait
            $params = [
                'amount'       => $amountUsd,      // Montant en USD (sans les frais)
                'currency'     => $currency,       // Devise cible (crypto)
                'currency2'    => 'USD',           // Devise source
                'address'      => $address,
                'auto_confirm' => 1,
                'ipn_url'      => $this->generateUrl('coinpayments_withdrawal_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'custom'       => $transaction->getId()
            ];

            // Log de la requête de retrait
            file_put_contents(
                __DIR__ . '/../../var/log/coinpayments_withdrawal_request.log',
                date('Y-m-d H:i:s') . ' - Params: ' . print_r($params, true),
                FILE_APPEND
            );

            $response = $this->coinPaymentsApiCall('create_withdrawal', $params);

            // Log de la réponse
            file_put_contents(
                __DIR__ . '/../../var/log/coinpayments_withdrawal_response.log',
                date('Y-m-d H:i:s') . ' - Response: ' . print_r($response, true),
                FILE_APPEND
            );

            if ($response['error'] !== 'ok') {
                throw new \Exception($response['error'] ?? 'Erreur inconnue lors de l\'appel à l\'API');
            }

            $this->addFlash('success', sprintf(
                'Votre demande de retrait de %.2f USD a été enregistrée. Des frais de %.2f USD ont été appliqués. La conversion en %s sera effectuée dans les plus brefs délais.',
                $amountUsd,
                $fees,
                $currency
            ));
        } catch (\Exception $e) {
            // Log de l'exception
            file_put_contents(
                __DIR__ . '/../../var/log/coinpayments_withdrawal_error.log',
                date('Y-m-d H:i:s') . ' - Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
                FILE_APPEND
            );

            // En cas d'erreur, on rembourse l'utilisateur et on marque la transaction comme échouée
            $user->setBalance($user->getBalance() + $totalAmount);
            $transaction->setStatus('failed');
            $em->flush();
            $this->addFlash('danger', 'Désolé, une erreur est survenue lors du traitement de votre demande de retrait: ' . $e->getMessage());
        }
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/coinpayments/withdrawal-ipn', name: 'coinpayments_withdrawal_ipn', methods: ['POST'])]
    public function coinpaymentsWithdrawalIpn(Request $request, EntityManagerInterface $em): Response
    {
        $ipnData = $request->request->all();

        // Logger les données pour le débogage
        file_put_contents(
            __DIR__ . '/../../var/log/coinpayments_withdrawal_ipn.log',
            date('Y-m-d H:i:s') . ' - IPN Data: ' . print_r($ipnData, true),
            FILE_APPEND
        );

        // Vérifier les champs nécessaires
        if (
            isset($ipnData['status']) && (int)$ipnData['status'] >= 100 &&
            isset($ipnData['custom']) &&
            isset($ipnData['amount'])
        ) {
            $transactionId = (int)$ipnData['custom'];
            $transaction = $em->getRepository(Transactions::class)->find($transactionId);

            if ($transaction && $transaction->getStatus() !== 'completed') {
                $transaction->setStatus('completed');
                // Nous ne modifions plus le solde de l'utilisateur ici car il a déjà été déduit lors de la création
                $em->flush();

                // Log du succès
                file_put_contents(
                    __DIR__ . '/../../var/log/coinpayments_withdrawal_success.log',
                    date('Y-m-d H:i:s') . ' - Transaction #' . $transactionId . ' complétée.',
                    FILE_APPEND
                );
            }
        }

        return new Response('IPN de retrait traité', 200);
    }

    // --- Dépôts ---
    #[Route('/deposit', name: 'app_deposit', methods: ['POST'])]
    public function deposit(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }
        $amount = (float)$request->request->get('amount');
        $paymentMethod = $request->request->get('paymentMethod');

        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant doit être supérieur à 0 USD. Veuillez saisir un montant valide.');
            return $this->redirectToRoute('app_profile');
        }
        switch ($paymentMethod) {
            case 'paypal':
                return $this->redirectToRoute('app_paypal_redirect', ['amount' => $amount]);
            case 'crypto':
                return $this->redirectToRoute('app_crypto_redirect', ['amount' => $amount]);
            default:
                $this->addFlash('danger', 'La méthode de paiement sélectionnée n\'est pas valide. Veuillez réessayer.');
                return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/deposit/redirect', name: 'app_crypto_redirect', methods: ['GET', 'POST'])]
    public function cryptoRedirect(Request $request, EntityManagerInterface $em): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant doit être supérieur à 0 USD. Veuillez saisir un montant valide.');
            return $this->redirectToRoute('app_profile');
        }
        if ($request->isMethod('GET')) {
            return $this->render('payment/crypto_deposit.html.twig', ['amount' => $amount]);
        }

        $cryptoType = strtoupper(trim($request->request->get('cryptoType')));
        $walletAddress = trim($request->request->get('walletAddress'));

        if (!$cryptoType || !$walletAddress) {
            $this->addFlash('danger', 'Veuillez sélectionner le type de crypto-monnaie et fournir l\'adresse de votre portefeuille.');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        // Créer la transaction
        $transaction = new Transactions();
        $transaction->setUser($user)
            ->setAmount($amount)
            ->setType('deposit')  // Ajout du type pour cohérence
            ->setMethod("crypto_deposit ($cryptoType)")
            ->setStatus('pending')
            ->setCreatedAt(new \DateTimeImmutable());
        $em->persist($transaction);
        $em->flush();

        // Paramètres pour CoinPayments
        $params = [
            'amount'      => $amount,
            'currency1'   => 'USD',
            'currency2'   => $cryptoType,
            'buyer_email' => $user->getEmail(),
            'item_name'   => 'Dépôt sur ' . $this->getParameter('app.site_name'),
            'ipn_url'     => $this->generateUrl('app_payment_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'success_url' => $this->generateUrl('app_profile', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'cancel_url'  => $this->generateUrl('app_profile', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'custom'      => $transaction->getId()
        ];

        try {
            // Log pour débogage
            file_put_contents(
                __DIR__ . '/../../var/log/coinpayments_deposit_request.log',
                date('Y-m-d H:i:s') . ' - Params: ' . print_r($params, true),
                FILE_APPEND
            );

            $response = $this->coinPaymentsApiCall('create_transaction', $params);

            // Log de la réponse
            file_put_contents(
                __DIR__ . '/../../var/log/coinpayments_deposit_response.log',
                date('Y-m-d H:i:s') . ' - Response: ' . print_r($response, true),
                FILE_APPEND
            );

            if ($response['error'] !== 'ok') {
                throw new \Exception($response['error'] ?? 'Erreur inconnue lors de l\'appel à l\'API');
            }

            // Mettre à jour la transaction avec les détails de CoinPayments
            if (isset($response['result']['txn_id'])) {
                $transaction->setExternalId($response['result']['txn_id']);
                $em->flush();
            }

            return $this->redirect($response['result']['checkout_url']);
        } catch (\Exception $e) {
            // Log de l'exception
            file_put_contents(
                __DIR__ . '/../../var/log/coinpayments_deposit_error.log',
                date('Y-m-d H:i:s') . ' - Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
                FILE_APPEND
            );

            // Marquer la transaction comme échouée
            $transaction->setStatus('failed');
            $em->flush();

            $this->addFlash('danger', 'Une erreur est survenue lors du dépôt: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/payment/ipn-handler', name: 'app_payment_ipn', methods: ['POST'])]
    public function handleIpn(Request $request, EntityManagerInterface $em): Response
    {
        $ipnData = $request->request->all();

        // Log plus détaillé
        file_put_contents(
            __DIR__ . '/../../var/log/ipn.log',
            date('Y-m-d H:i:s') . ' - IPN Data: ' . print_r($ipnData, true),
            FILE_APPEND
        );

        if (
            isset($ipnData['status']) && (int)$ipnData['status'] >= 100 &&
            isset($ipnData['txn_id']) &&
            isset($ipnData['amount1']) &&
            isset($ipnData['custom'])
        ) {
            $txnId = $ipnData['txn_id'];
            $amount = (float)$ipnData['amount1'];
            $transactionId = (int)$ipnData['custom'];

            $transaction = $em->getRepository(Transactions::class)->find($transactionId);
            if ($transaction && $transaction->getStatus() !== 'completed') {
                $transaction->setStatus('completed');
                $user = $transaction->getUser();
                if ($user) {
                    $user->setBalance($user->getBalance() + $transaction->getAmount());

                    // Log du succès
                    file_put_contents(
                        __DIR__ . '/../../var/log/deposit_success.log',
                        date('Y-m-d H:i:s') . ' - Transaction #' . $transactionId . ' complétée. Montant: ' . $amount,
                        FILE_APPEND
                    );
                }
                $em->flush();
            }
        }
        return new Response('IPN traité', 200);
    }

    #[Route('/paypal/redirect', name: 'app_paypal_redirect')]
    public function paypalRedirect(Request $request): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant saisi est invalide. Veuillez saisir un montant supérieur à 0 USD.');
            return $this->redirectToRoute('app_profile');
        }

        try {
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

            // Log de la requête PayPal
            file_put_contents(
                __DIR__ . '/../../var/log/paypal_request.log',
                date('Y-m-d H:i:s') . ' - Request: ' . print_r($paypalRequest->body, true),
                FILE_APPEND
            );

            $response = $client->execute($paypalRequest);

            // Log de la réponse PayPal
            file_put_contents(
                __DIR__ . '/../../var/log/paypal_response.log',
                date('Y-m-d H:i:s') . ' - Order ID: ' . $response->result->id,
                FILE_APPEND
            );

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
            $request->getSession()->set('paypal_amount', $amount);
            return $this->redirect($approveUrl);
        } catch (\Exception $e) {
            // Log de l'erreur PayPal
            file_put_contents(
                __DIR__ . '/../../var/log/paypal_error.log',
                date('Y-m-d H:i:s') . ' - Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
                FILE_APPEND
            );

            $this->addFlash('danger', 'Une erreur est survenue lors de la redirection vers PayPal: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/paypal/return', name: 'paypal_return')]
    public function paypalReturn(Request $request, EntityManagerInterface $em): Response
    {
        $orderId = $request->query->get('token') ?? $request->getSession()->get('paypal_order_id');
        $amount = $request->getSession()->get('paypal_amount');
        if (!$orderId || !$amount) {
            $this->addFlash('danger', 'Impossible de retrouver votre commande PayPal. Veuillez réessayer.');
            return $this->redirectToRoute('app_profile');
        }
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $client = $this->getPayPalClient();
        $captureRequest = new OrdersCaptureRequest($orderId);
        try {
            $response = $client->execute($captureRequest);

            // Log de la réponse de capture
            file_put_contents(
                __DIR__ . '/../../var/log/paypal_capture.log',
                date('Y-m-d H:i:s') . ' - Order ID: ' . $orderId . ' - Status: ' . $response->result->status,
                FILE_APPEND
            );

            if ($response->result->status !== 'COMPLETED') {
                throw new \Exception('Paiement non complété: ' . $response->result->status);
            }

            $transaction = (new Transactions())
                ->setUser($user)
                ->setAmount($amount)
                ->setType('deposit')
                ->setMethod('paypal')
                ->setStatus('completed')
                ->setExternalId($orderId)
                ->setCreatedAt(new \DateTimeImmutable());
            $user->setBalance($user->getBalance() + $amount);
            $em->persist($transaction);
            $em->persist($user);
            $em->flush();

            $request->getSession()->remove('paypal_order_id');
            $request->getSession()->remove('paypal_amount');
            $this->addFlash('success', sprintf('Votre dépôt de %.2f USD a été effectué avec succès.', $amount));
        } catch (\Exception $e) {
            // Log de l'erreur de capture
            file_put_contents(
                __DIR__ . '/../../var/log/paypal_capture_error.log',
                date('Y-m-d H:i:s') . ' - Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString(),
                FILE_APPEND
            );

            $this->addFlash('danger', 'Une erreur est survenue lors du traitement de votre paiement PayPal: ' . $e->getMessage());
        }
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/paypal/cancel', name: 'paypal_cancel')]
    public function paypalCancel(Request $request): Response
    {
        $request->getSession()->remove('paypal_order_id');
        $request->getSession()->remove('paypal_amount');
        $this->addFlash('warning', 'Votre paiement PayPal a été annulé.');
        return $this->redirectToRoute('app_profile');
    }

    private function coinPaymentsApiCall(string $cmd, array $params = []): array
    {
        // Vérifier que les clés API sont configurées
        if (empty($_ENV['COINPAYMENTS_API_SECRET']) || empty($_ENV['COINPAYMENTS_API_KEY'])) {
            throw new \Exception("Les clés API CoinPayments ne sont pas configurées");
        }

        $privateKey = $_ENV['COINPAYMENTS_API_SECRET'];
        $publicKey  = $_ENV['COINPAYMENTS_API_KEY'];

        // On ajoute le nonce (timestamp) et les autres paramètres obligatoires
        $params += [
            'version' => 1,
            'cmd'     => $cmd,
            'key'     => $publicKey,
            'format'  => 'json',
            'nonce'   => time(),            // <<<--- ici !
        ];

        $postData = http_build_query($params, '', '&');
        $hmac     = hash_hmac('sha512', $postData, $privateKey);

        $ch = curl_init('https://www.coinpayments.net/api.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_SSL_VERIFYPEER    => true,
            CURLOPT_HTTPHEADER        => ["HMAC: $hmac"],
            CURLOPT_POST              => true,
            CURLOPT_POSTFIELDS        => $postData,
            CURLOPT_TIMEOUT           => 30,
        ]);

        $data = curl_exec($ch);
        if ($data === false) {
            $err  = curl_error($ch);
            $info = curl_getinfo($ch);
            curl_close($ch);
            throw new \Exception("Erreur cURL: $err – Info: " . print_r($info, true));
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            throw new \Exception("Réponse HTTP $httpCode – Réponse brute: $data");
        }

        $result = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("JSON invalide: " . json_last_error_msg() . " – Données: $data");
        }

        return $result;
    }


    private function getPayPalClient(): PayPalHttpClient
    {
        // Vérifier que les clés API sont configurées
        if (empty($_ENV['PAYPAL_CLIENT_ID']) || empty($_ENV['PAYPAL_CLIENT_SECRET'])) {
            throw new \Exception("Les clés API PayPal ne sont pas configurées");
        }

        $clientId = $_ENV['PAYPAL_CLIENT_ID'];
        $clientSecret = $_ENV['PAYPAL_CLIENT_SECRET'];
        $environment = $_ENV['APP_ENV'] === 'prod'
            ? new ProductionEnvironment($clientId, $clientSecret)
            : new SandboxEnvironment($clientId, $clientSecret);
        return new PayPalHttpClient($environment);
    }

    // --- Méthodes de paiement non implémentées ---
    private function processCardPayment(User $user, float $amount): bool
    {
        return false;
    }

    private function processMobileMoney(User $user, float $amount): bool
    {
        return false;
    }
}
