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
            $this->addFlash('danger', 'Le montant demandé est invalide ou votre solde est insuffisant.');
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
            // Envoi de la demande de retrait à CoinPayments
            $params = [
                'amount'       => $amountUsd,      // Montant en USD
                'currency'     => $currency,       // Devise cible (crypto)
                'currency2'    => 'USD',           // Devise source
                'address'      => $address,
                'auto_confirm' => 1,
                'ipn_url'      => $this->generateUrl('coinpayments_withdrawal_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'custom'       => $transaction->getId()
            ];
            $response = $this->coinPaymentsApiCall('create_withdrawal', $params);
            if ($response['error'] !== 'ok') {
                throw new \Exception($response['error'] ?? 'Erreur inconnue de CoinPayments');
            }
            $this->addFlash('success', sprintf(
                'Votre demande de retrait de %.2f USD a bien été envoyée. La conversion en %s sera prise en charge par CoinPayments.',
                $amountUsd,
                $currency
            ));
        } catch (\Exception $e) {
            $transaction->setStatus('failed');
            $em->flush();
            $this->addFlash('danger', 'Désolé, une erreur est survenue lors du traitement de votre demande de retrait. Veuillez réessayer ultérieurement.');
        }
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/coinpayments/withdrawal-ipn', name: 'coinpayments_withdrawal_ipn', methods: ['POST'])]
    public function coinpaymentsWithdrawalIpn(Request $request, EntityManagerInterface $em): Response
    {
        $ipnData = $request->request->all();

        // Logger les données pour le débogage
        file_put_contents(__DIR__ . '/../../var/log/coinpayments_withdrawal_ipn.log', print_r($ipnData, true), FILE_APPEND);

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
                $user = $transaction->getUser();

                if ($user) {
                    // Vérifier que le montant n'a pas déjà été soustrait
                    if ($user->getBalance() >= $transaction->getAmount()) {
                        $user->setBalance($user->getBalance() - $transaction->getAmount());
                    } else {
                        file_put_contents(__DIR__ . '/../../var/log/withdrawal_error.log', "Solde insuffisant pour l'utilisateur #" . $user->getId() . "\n", FILE_APPEND);
                    }
                    $em->persist($user);
                }
                $em->flush();
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
        $cryptoType = strtoupper(trim($request->request->get('cryptoType')));

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
        $cryptoType    = strtoupper(trim($request->request->get('cryptoType')));
        $walletAddress = trim($request->request->get('walletAddress'));
        if (!$cryptoType || !$walletAddress) {
            $this->addFlash('danger', 'Veuillez sélectionner le type de crypto-monnaie et fournir l\'adresse de votre portefeuille.');
            return $this->redirectToRoute('app_profile');
        }
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }
        $transaction = new Transactions();
        $transaction->setUser($user)
            ->setAmount($amount)
            ->setMethod("crypto_deposit ($cryptoType)")
            ->setStatus('pending')
            ->setCreatedAt(new \DateTimeImmutable());
        $em->persist($transaction);
        $em->flush();

        $params = [
            'amount'      => $amount,
            'currency1'   => 'USD',
            'currency2'   => $cryptoType,
            'buyer_email' => $user->getEmail(),
            'item_name'   => 'Dépôt sur ' . $this->getParameter('app.site_name'),
            'ipn_url'     => $this->generateUrl('app_payment_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'success_url' => $this->generateUrl('app_profile', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'cancel_url'  => $this->generateUrl('app_profile', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'custom'      => $transaction->getId(),
        ];
        try {
            $response = $this->coinPaymentsApiCall('create_transaction', $params);
            if ($response['error'] !== 'ok') {
                throw new \Exception($response['error'] ?? 'Erreur inconnue de CoinPayments');
            }
            return $this->redirect($response['result']['checkout_url']);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Une erreur est survenue lors du dépôt via CoinPayments. Veuillez réessayer ultérieurement.');
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/payment/ipn-handler', name: 'app_payment_ipn', methods: ['POST'])]
    public function handleIpn(Request $request, EntityManagerInterface $em): Response
    {
        $ipnData = $request->request->all();
        file_put_contents(__DIR__ . '/../../var/log/ipn.log', print_r($ipnData, true), FILE_APPEND);

        if (
            isset($ipnData['status']) && (int)$ipnData['status'] >= 100 &&
            isset($ipnData['txn_id']) &&
            isset($ipnData['amount1']) &&
            isset($ipnData['custom'])
        ) {
            $txnId         = $ipnData['txn_id'];
            $amount        = (float)$ipnData['amount1'];
            $transactionId = (int)$ipnData['custom'];

            $transaction = $em->getRepository(Transactions::class)->find($transactionId);
            if ($transaction) {
                $transaction->setStatus('completed');
                $user = $transaction->getUser();
                if ($user) {
                    $user->setBalance($user->getBalance() + $transaction->getAmount());
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
            $request->getSession()->set('paypal_order_id', $response->result->id);
            $request->getSession()->set('paypal_amount', $amount);
            return $this->redirect($approveUrl);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Une erreur est survenue lors de la redirection vers PayPal. Veuillez réessayer ultérieurement.');
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
            $request->getSession()->remove('paypal_order_id');
            $request->getSession()->remove('paypal_amount');
            $this->addFlash('success', sprintf('Votre dépôt de %.2f USD a été effectué avec succès.', $amount));
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Une erreur est survenue lors du traitement de votre paiement PayPal. Veuillez réessayer ultérieurement.');
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
        $privateKey = $_ENV['COINPAYMENTS_API_SECRET'];
        $publicKey  = $_ENV['COINPAYMENTS_API_KEY'];
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
            CURLOPT_HTTPHEADER     => ["hmac: $hmac"],
            CURLOPT_POSTFIELDS     => $postData
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
