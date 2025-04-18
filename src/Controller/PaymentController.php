<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, RedirectResponse};
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\{Transactions, User};
use PayPalCheckoutSdk\Core\{PayPalHttpClient, SandboxEnvironment, ProductionEnvironment};
use PayPalCheckoutSdk\Orders\{OrdersCreateRequest, OrdersCaptureRequest};
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use DateTimeImmutable;

class PaymentController extends AbstractController
{
    private const MIN_WITHDRAWAL_AMOUNT = 2.0; // Minimum 2 USD
    private const MIN_DEPOSIT_AMOUNT = 1.0; // Minimum 1 USD
    private const MAX_DEPOSIT_AMOUNT = 10000.0;
    private const MAX_WITHDRAWAL_AMOUNT = 5000.0;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private SessionInterface $session
    ) {
    }

    /**
     * Calcule les frais de retrait en fonction du montant demandé
     */
    private function calculateWithdrawalFees(float $amount): float
    {
        if ($amount <= 20) {
            $feePercentage = 0.05; // 5% pour $2-$20
        } elseif ($amount <= 100) {
            $feePercentage = 0.03; // 3% pour $20.01-$100
        } elseif ($amount <= 500) {
            $feePercentage = 0.02; // 2% pour $100.01-$500
        } else {
            $feePercentage = 0.01; // 1% pour $500.01+
        }

        return max($amount * $feePercentage, 1.0); // Frais minimum de $1
    }

    #[Route('/withdraw', name: 'app_withdraw', methods: ['POST'])]
    public function withdraw(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        $amountUsd = (float)$request->request->get('amount');
        $currency = strtoupper(trim($request->request->get('currency')));
        $address = trim($request->request->get('recipient'));

        // Validation
        if ($amountUsd < self::MIN_WITHDRAWAL_AMOUNT) {
            return $this->redirectWithFlash('danger', sprintf(
                'Le montant de retrait doit être d\'au moins %.2f USD.',
                self::MIN_WITHDRAWAL_AMOUNT
            ));
        }

        if (empty($address)) {
            return $this->redirectWithFlash('danger', 'Veuillez fournir une adresse de portefeuille valide.');
        }

        // Calcul des frais
        $fees = $this->calculateWithdrawalFees($amountUsd);
        $totalAmount = $amountUsd + $fees;

        // Vérification du solde
        if ($totalAmount > $user->getBalance()) {
            return $this->redirectWithFlash('danger', sprintf(
                'Solde insuffisant. Le retrait de %.2f USD nécessite %.2f USD de frais, soit un total de %.2f USD.',
                $amountUsd,
                $fees,
                $totalAmount
            ));
        }

        // Création de la transaction
        $transaction = (new Transactions())
            ->setUser($user)
            ->setType('withdrawal')
            ->setAmount($amountUsd)
            ->setFees($fees)
            ->setMethod("Crypto ($currency)")
            ->setStatus('pending')
            ->setCreatedAt(new DateTimeImmutable());

        $this->entityManager->beginTransaction();
        try {
            // Déduire le montant total du solde
            $user->setBalance($user->getBalance() - $totalAmount);
            
            $this->entityManager->persist($transaction);
            $this->entityManager->persist($user);
            $this->entityManager->flush();

            // Envoi à CoinPayments
            $response = $this->coinPaymentsWithdrawal($transaction, $currency, $address);
            
            $this->entityManager->commit();
            return $this->redirectWithFlash('success', sprintf(
                'Votre demande de retrait de %.2f USD a été enregistrée. Des frais de %.2f USD ont été appliqués.',
                $amountUsd,
                $fees
            ));
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logError('Withdrawal failed', [
                'error' => $e->getMessage(),
                'user' => $user->getId(),
                'amount' => $amountUsd
            ]);
            return $this->redirectWithFlash('danger', 'Erreur lors du traitement: ' . $e->getMessage());
        }
    }

    private function coinPaymentsWithdrawal(Transactions $transaction, string $currency, string $address): array
    {
        $params = [
            'amount'       => $transaction->getAmount(),
            'currency'     => $currency,
            'currency2'   => 'USD',
            'address'      => $address,
            'auto_confirm' => 1,
            'ipn_url'      => $this->generateUrl('coinpayments_withdrawal_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'custom'       => $transaction->getId()
        ];

        $this->logInfo('CoinPayments withdrawal request', $params);
        $response = $this->coinPaymentsApiCall('create_withdrawal', $params);
        $this->logInfo('CoinPayments withdrawal response', $response);

        if ($response['error'] !== 'ok') {
            throw new \Exception($response['error'] ?? 'Erreur inconnue de CoinPayments');
        }

        return $response;
    }

    #[Route('/coinpayments/withdrawal-ipn', name: 'coinpayments_withdrawal_ipn', methods: ['POST'])]
    public function coinpaymentsWithdrawalIpn(Request $request): Response
    {
        $ipnData = $request->request->all();
        $this->logInfo('CoinPayments withdrawal IPN', $ipnData);

        if (isset($ipnData['status'], $ipnData['custom']) && (int)$ipnData['status'] >= 100) {
            $transaction = $this->entityManager->getRepository(Transactions::class)
                ->find((int)$ipnData['custom']);

            if ($transaction && $transaction->getStatus() === 'pending') {
                $transaction->setStatus('completed');
                $this->entityManager->flush();
                $this->logInfo('Withdrawal completed', ['transaction' => $transaction->getId()]);
            }
        }

        return new Response('IPN received');
    }

    #[Route('/deposit', name: 'app_deposit', methods: ['POST'])]
    public function deposit(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $amount = (float)$request->request->get('amount');
        $paymentMethod = $request->request->get('paymentMethod');

        // Validation
        if ($amount < self::MIN_DEPOSIT_AMOUNT) {
            return $this->redirectWithFlash('danger', sprintf(
                'Le montant minimum de dépôt est de %.2f USD.',
                self::MIN_DEPOSIT_AMOUNT
            ));
        }

        switch ($paymentMethod) {
            case 'paypal':
                return $this->redirectToRoute('app_paypal_redirect', ['amount' => $amount]);
            case 'crypto':
                return $this->handleCryptoDeposit($amount);
            default:
                return $this->redirectWithFlash('danger', 'Méthode de paiement invalide');
        }
    }

    private function handleCryptoDeposit(float $amount): Response
    {
        $user = $this->getUser();
        $transaction = (new Transactions())
            ->setUser($user)
            ->setAmount($amount)
            ->setType('deposit')
            ->setMethod('crypto')
            ->setStatus('pending')
            ->setCreatedAt(new DateTimeImmutable());

        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        return $this->render('payment/crypto_deposit.html.twig', [
            'transaction' => $transaction,
            'amount' => $amount,
            'expiresAt' => (new \DateTime('+15 minutes'))->format('Y-m-d H:i:s')
        ]);
    }

    #[Route('/deposit/confirm/{id}', name: 'app_deposit_confirm', methods: ['POST'])]
    public function confirmDeposit(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if (!$transaction || $transaction->getUser() !== $this->getUser()) {
            return $this->redirectWithFlash('danger', 'Transaction invalide');
        }

        $cryptoType = strtoupper($request->request->get('cryptoType'));
        $txHash = trim($request->request->get('txHash'));

        if (empty($cryptoType) || empty($txHash)) {
            return $this->redirectWithFlash('danger', 'Veuillez fournir tous les détails de la transaction');
        }

        // Enregistrer les détails de la transaction crypto
        $transaction
            ->setMethod("crypto_$cryptoType")
            ->setExternalId($txHash)
            ->setStatus('pending_verification');

        $this->entityManager->flush();

        return $this->redirectWithFlash('success', 
            'Votre dépôt est en attente de confirmation. Nous vérifierons la transaction blockchain sous peu.'
        );
    }

    #[Route('/paypal/redirect', name: 'app_paypal_redirect')]
    public function paypalRedirect(Request $request): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount < self::MIN_DEPOSIT_AMOUNT) {
            return $this->redirectWithFlash('danger', sprintf(
                'Le montant minimum de dépôt est de %.2f USD.',
                self::MIN_DEPOSIT_AMOUNT
            ));
        }

        try {
            $client = $this->getPayPalClient();
            $paypalRequest = new OrdersCreateRequest();
            $paypalRequest->prefer('return=representation');
            $paypalRequest->body = $this->createPayPalOrder($amount);

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

            $this->session->set('paypal_order_id', $response->result->id);
            $this->session->set('paypal_amount', $amount);
            return $this->redirect($approveUrl);
        } catch (\Exception $e) {
            $this->logError('PayPal redirect failed', ['error' => $e->getMessage()]);
            return $this->redirectWithFlash('danger', 'Erreur lors de la redirection PayPal');
        }
    }

    #[Route('/paypal/return', name: 'paypal_return')]
    public function paypalReturn(Request $request): Response
    {
        $orderId = $request->query->get('token') ?? $this->session->get('paypal_order_id');
        $amount = $this->session->get('paypal_amount');

        if (!$orderId || !$amount) {
            return $this->redirectWithFlash('danger', 'Commande PayPal introuvable');
        }

        $user = $this->getUser();
        $this->entityManager->beginTransaction();

        try {
            $client = $this->getPayPalClient();
            $response = $client->execute(new OrdersCaptureRequest($orderId));

            if ($response->result->status !== 'COMPLETED') {
                throw new \Exception('Statut PayPal non complet: ' . $response->result->status);
            }

            $transaction = (new Transactions())
                ->setUser($user)
                ->setAmount($amount)
                ->setType('deposit')
                ->setMethod('paypal')
                ->setStatus('completed')
                ->setExternalId($orderId)
                ->setCreatedAt(new DateTimeImmutable());

            $user->setBalance($user->getBalance() + $amount);

            $this->entityManager->persist($transaction);
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->session->remove('paypal_order_id');
            $this->session->remove('paypal_amount');
            
            return $this->redirectWithFlash('success', sprintf(
                'Dépôt de %.2f USD effectué avec succès',
                $amount
            ));
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logError('PayPal return failed', ['error' => $e->getMessage()]);
            return $this->redirectWithFlash('danger', 'Erreur lors du traitement PayPal');
        }
    }

    #[Route('/paypal/cancel', name: 'paypal_cancel')]
    public function paypalCancel(): Response
    {
        $this->session->remove('paypal_order_id');
        $this->session->remove('paypal_amount');
        return $this->redirectWithFlash('warning', 'Paiement PayPal annulé');
    }

    private function createPayPalOrder(float $amount): array
    {
        return [
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
    }

    private function coinPaymentsApiCall(string $cmd, array $params = []): array
    {
        if (empty($_ENV['COINPAYMENTS_API_SECRET']) || empty($_ENV['COINPAYMENTS_API_KEY'])) {
            throw new \Exception("CoinPayments API keys not configured");
        }

        $params += [
            'version' => 1,
            'cmd' => $cmd,
            'key' => $_ENV['COINPAYMENTS_API_KEY'],
            'format' => 'json',
            'nonce' => time(),
        ];

        $postData = http_build_query($params, '', '&');
        $hmac = hash_hmac('sha512', $postData, $_ENV['COINPAYMENTS_API_SECRET']);

        $ch = curl_init('https://www.coinpayments.net/api.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["HMAC: $hmac"],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
        ]);

        $response = curl_exec($ch);
        if (!$response) {
            throw new \Exception('CoinPayments API error: ' . curl_error($ch));
        }
        curl_close($ch);

        return json_decode($response, true) ?? [];
    }

    private function getPayPalClient(): PayPalHttpClient
    {
        if (empty($_ENV['PAYPAL_CLIENT_ID']) || empty($_ENV['PAYPAL_CLIENT_SECRET'])) {
            throw new \Exception("PayPal API keys not configured");
        }

        $environment = $_ENV['APP_ENV'] === 'prod'
            ? new ProductionEnvironment($_ENV['PAYPAL_CLIENT_ID'], $_ENV['PAYPAL_CLIENT_SECRET'])
            : new SandboxEnvironment($_ENV['PAYPAL_CLIENT_ID'], $_ENV['PAYPAL_CLIENT_SECRET']);

        return new PayPalHttpClient($environment);
    }

    private function redirectWithFlash(string $type, string $message): RedirectResponse
    {
        $this->addFlash($type, $message);
        return $this->redirectToRoute('app_profile');
    }

    private function logInfo(string $message, array $context = []): void
    {
        file_put_contents(
            __DIR__ . '/../../var/log/payment.log',
            date('[Y-m-d H:i:s]') . ' INFO: ' . $message . ' ' . json_encode($context) . "\n",
            FILE_APPEND
        );
    }

    private function logError(string $message, array $context = []): void
    {
        file_put_contents(
            __DIR__ . '/../../var/log/payment_error.log',
            date('[Y-m-d H:i:s]') . ' ERROR: ' . $message . ' ' . json_encode($context) . "\n",
            FILE_APPEND
        );
    }
}