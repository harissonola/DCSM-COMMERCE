<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request, Response, RedirectResponse, JsonResponse};
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\{Transactions, User};
use PayPalCheckoutSdk\Core\{PayPalHttpClient, SandboxEnvironment, ProductionEnvironment};
use PayPalCheckoutSdk\Orders\{OrdersCreateRequest, OrdersCaptureRequest};
use Symfony\Component\HttpFoundation\RequestStack;
use DateTimeImmutable;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use GuzzleHttp\Client;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

class PaymentController extends AbstractController
{
    public const MIN_WITHDRAWAL_AMOUNT = 2.0;
    public const MIN_DEPOSIT_AMOUNT = 1.0;
    public const MAX_DEPOSIT_AMOUNT = 10000.0;
    public const MAX_WITHDRAWAL_AMOUNT = 5000.0;
    public const DEPOSIT_EXPIRATION_HOURS = 2;

    public const SUPPORTED_CRYPTOS = [
        'btc' => 'Bitcoin (BTC)',
        'eth' => 'Ethereum (ETH)',
        'ltc' => 'Litecoin (LTC)',
        'usdt' => 'Tether (USDT)',
        'usdc' => 'USD Coin (USDC)',
        'doge' => 'Dogecoin (DOGE)',
        'bch' => 'Bitcoin Cash (BCH)',
        'xrp' => 'Ripple (XRP)',
        'trx' => 'TRON (TRX)'
    ];

    private $mailer;
    private $httpClient;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private CsrfTokenManagerInterface $csrfTokenManager,
        MailerInterface $mailer
    ) {
        $this->httpClient = new Client();
        $this->mailer = $mailer;
    }

    private function getSession()
    {
        return $this->requestStack->getSession();
    }

    private function calculateWithdrawalFees(float $amount): float
    {
        if ($amount <= 20) {
            return max($amount * 0.05, 1.0);
        } elseif ($amount <= 100) {
            return $amount * 0.03;
        } elseif ($amount <= 500) {
            return $amount * 0.02;
        } else {
            return $amount * 0.01;
        }
    }

    #[Route('/withdraw', name: 'app_withdraw', methods: ['POST'])]
    public function withdraw(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $token = new CsrfToken('withdraw', $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->json(['error' => 'Token CSRF invalide'], 403);
        }

        $user = $this->getUser();
        $amount = (float)$request->request->get('amount');
        $currency = strtolower(trim($request->request->get('currency')));
        $address = trim($request->request->get('address'));

        $errors = $this->validateWithdrawal($user, $amount, $currency, $address);
        if (!empty($errors)) {
            return $this->redirectWithFlash('danger', $errors[0]);
        }

        $fees = $this->calculateWithdrawalFees($amount);
        $totalAmount = $amount + $fees;

        $transaction = (new Transactions())
            ->setUser($user)
            ->setType('withdrawal')
            ->setAmount($amount)
            ->setFees($fees)
            ->setMethod('crypto_' . $currency)
            ->setStatus('pending')
            ->setCreatedAt(new DateTimeImmutable())
            ->setExternalId($address);

        $this->entityManager->beginTransaction();
        try {
            $user->setBalance($user->getBalance() - $totalAmount);
            $this->entityManager->persist($transaction);
            $this->entityManager->flush();

            $this->processWithdrawal($transaction, $currency, $address);

            $this->entityManager->commit();
            return $this->redirectWithFlash('success', sprintf(
                'Demande de retrait de %.2f USD enregistrée. Frais: %.2f USD',
                $amount,
                $fes
            ));
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logError('Withdrawal failed', ['error' => $e->getMessage()]);
            return $this->redirectWithFlash('danger', 'Erreur lors du traitement du retrait');
        }
    }

    #[Route('/deposit', name: 'app_deposit', methods: ['POST'])]
    public function deposit(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $token = new CsrfToken('deposit', $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->redirectWithFlash('danger', 'Token CSRF invalide');
        }

        $amount = (float)$request->request->get('amount');
        $method = $request->request->get('method');

        $errors = $this->validateDeposit($amount, $method);
        if (!empty($errors)) {
            return $this->redirectWithFlash('danger', $errors[0]);
        }

        $user = $this->getUser();
        $transaction = (new Transactions())
            ->setUser($user)
            ->setAmount($amount)
            ->setType('deposit')
            ->setMethod($method)
            ->setStatus('pending')
            ->setCreatedAt(new DateTimeImmutable());

        $this->entityManager->persist($transaction);
        $this->entityManager->flush();

        if ($method === 'crypto') {
            return $this->redirectToRoute('app_select_crypto', ['id' => $transaction->getId()]);
        }

        return $this->redirectToRoute('app_paypal_redirect', ['id' => $transaction->getId()]);
    }

    #[Route('/deposit/crypto/select/{id}', name: 'app_select_crypto')]
    public function selectCrypto(int $id): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if (!$transaction || $transaction->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Transaction invalide');
        }

        return $this->render('payment/select_crypto.html.twig', [
            'transaction' => $transaction,
            'supportedCryptos' => self::SUPPORTED_CRYPTOS,
            'csrf_token' => $this->csrfTokenManager->getToken('select_crypto')->getValue()
        ]);
    }

    #[Route('/deposit/crypto/process/{id}', name: 'app_process_crypto_deposit', methods: ['POST'])]
    public function processCryptoDeposit(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $token = new CsrfToken('select_crypto', $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->redirectWithFlash('danger', 'Token CSRF invalide');
        }

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if (!$transaction || $transaction->getUser() !== $this->getUser()) {
            return $this->redirectWithFlash('danger', 'Transaction invalide');
        }

        $cryptoType = strtolower($request->request->get('crypto_type'));
        $sourceAddress = trim($request->request->get('source_address'));

        if (!array_key_exists($cryptoType, self::SUPPORTED_CRYPTOS)) {
            return $this->redirectWithFlash('danger', 'Type de crypto non supporté');
        }

        if (empty($sourceAddress)) {
            return $this->redirectWithFlash('danger', 'Veuillez fournir votre adresse source');
        }

        try {
            $depositData = $this->createNowPaymentsDeposit(
                $cryptoType,
                $transaction->getAmount(),
                $transaction->getUser()->getId(),
                $transaction->getUser()->getEmail()
            );

            $transaction
                ->setMethod('crypto_' . $cryptoType)
                ->setExternalId($depositData['payment_id'])
                ->setMetadata([
                    'np_address' => $depositData['pay_address'],
                    'np_id' => $depositData['payment_id'],
                    'np_expiry' => $depositData['expiry_estimated_date'],
                    'source_address' => $sourceAddress
                ]);

            $this->entityManager->flush();

            $this->sendDepositInstructionsEmail(
                $transaction->getUser()->getEmail(),
                $depositData['pay_address'],
                $transaction->getAmount(),
                $cryptoType,
                new \DateTime($depositData['expiry_estimated_date']),
                $sourceAddress
            );

            return $this->render('payment/crypto_deposit_instructions.html.twig', [
                'transaction' => $transaction,
                'amount' => $transaction->getAmount(),
                'depositAddress' => $depositData['pay_address'],
                'sourceAddress' => $sourceAddress,
                'expiresAt' => new \DateTime($depositData['expiry_estimated_date']),
                'network' => self::SUPPORTED_CRYPTOS[$cryptoType],
                'qrCodeUrl' => $this->generateQrCodeUrl($depositData['pay_address']),
                'initialExpiration' => strtotime($depositData['expiry_estimated_date']) - time(),
                'csrf_token' => $this->csrfTokenManager->getToken('crypto_deposit')->getValue()
            ]);

        } catch (\Exception $e) {
            $this->logError('NowPayments deposit failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->redirectWithFlash('danger', 'Erreur lors de la création du dépôt: ' . $e->getMessage());
        }
    }

    #[Route('/deposit/paypal/{id}', name: 'app_paypal_redirect')]
    public function paypalRedirect(int $id): Response
    {
        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if (!$transaction || $transaction->getUser() !== $this->getUser()) {
            return $this->redirectWithFlash('danger', 'Transaction invalide');
        }

        try {
            $client = $this->getPayPalClient();
            $request = new OrdersCreateRequest();
            $request->prefer('return=representation');
            $request->body = [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => number_format($transaction->getAmount(), 2)
                    ],
                    'description' => 'Dépôt sur votre compte'
                ]],
                'application_context' => [
                    'return_url' => $this->generateUrl('paypal_return', ['id' => $transaction->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                    'cancel_url' => $this->generateUrl('paypal_cancel', ['id' => $transaction->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
                    'brand_name' => $this->getParameter('app.site_name'),
                    'user_action' => 'PAY_NOW'
                ]
            ];

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

            $this->getSession()->set('paypal_order_id', $response->result->id);
            return $this->redirect($approveUrl);
        } catch (\Exception $e) {
            $this->logError('PayPal redirect failed', ['error' => $e->getMessage()]);
            return $this->redirectWithFlash('danger', 'Erreur lors de la redirection PayPal');
        }
    }

    #[Route('/deposit/paypal/return/{id}', name: 'paypal_return')]
    public function paypalReturn(int $id): Response
    {
        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if (!$transaction || $transaction->getUser() !== $this->getUser()) {
            return $this->redirectWithFlash('danger', 'Transaction invalide');
        }

        $orderId = $this->getSession()->get('paypal_order_id');
        if (!$orderId) {
            return $this->redirectWithFlash('danger', 'Commande PayPal introuvable');
        }

        $this->entityManager->beginTransaction();
        try {
            $client = $this->getPayPalClient();
            $response = $client->execute(new OrdersCaptureRequest($orderId));

            if ($response->result->status !== 'COMPLETED') {
                throw new \Exception('Paiement non complété: ' . $response->result->status);
            }

            $user = $transaction->getUser();
            $user->setBalance($user->getBalance() + $transaction->getAmount());

            $transaction
                ->setStatus('completed')
                ->setExternalId($orderId)
                ->setVerifiedAt(new DateTimeImmutable());

            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->getSession()->remove('paypal_order_id');
            return $this->redirectWithFlash('success', 'Dépôt PayPal effectué avec succès');
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logError('PayPal return failed', ['error' => $e->getMessage()]);
            return $this->redirectWithFlash('danger', 'Erreur lors du traitement PayPal');
        }
    }

    #[Route('/deposit/paypal/cancel/{id}', name: 'paypal_cancel')]
    public function paypalCancel(int $id): Response
    {
        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if ($transaction && $transaction->getUser() === $this->getUser()) {
            $transaction->setStatus('cancelled');
            $this->entityManager->flush();
        }

        $this->getSession()->remove('paypal_order_id');
        return $this->redirectWithFlash('warning', 'Paiement PayPal annulé');
    }

    #[Route('/deposit/crypto/check/{id}', name: 'app_check_crypto_deposit', methods: ['POST'])]
    public function checkCryptoDeposit(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $token = new CsrfToken('crypto_deposit', $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->json(['error' => 'Token CSRF invalide'], 403);
        }

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if (!$transaction || $transaction->getUser() !== $this->getUser()) {
            return $this->json(['error' => 'Transaction invalide'], 403);
        }

        if ($transaction->getStatus() === 'completed') {
            return $this->json(['status' => 'completed']);
        }

        try {
            $paymentStatus = $this->checkNowPaymentsStatus($transaction->getExternalId());
            
            if ($paymentStatus === 'finished') {
                $this->completeDeposit($transaction);
                return $this->json(['status' => 'completed']);
            } elseif ($paymentStatus === 'expired') {
                $transaction->setStatus('expired');
                $this->entityManager->flush();
                return $this->json(['status' => 'expired']);
            }

            return $this->json(['status' => 'pending']);
        } catch (\Exception $e) {
            return $this->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    #[Route('/nowpayments/ipn', name: 'nowpayments_ipn', methods: ['POST'])]
    public function handleNowPaymentsIPN(Request $request): Response
    {
        $content = $request->getContent();
        $data = json_decode($content, true);
        $providedHmac = $request->headers->get('x-nowpayments-sig');

        $calculatedHmac = hash_hmac('sha512', $content, $_ENV['NOWPAYMENTS_IPN_SECRET']);
        if (!hash_equals($calculatedHmac, $providedHmac)) {
            $this->logError('Invalid IPN HMAC', [
                'provided' => $providedHmac,
                'calculated' => $calculatedHmac
            ]);
            return new Response('Invalid HMAC', 403);
        }

        $transaction = $this->entityManager->getRepository(Transactions::class)
            ->findOneBy(['external_id' => $data['payment_id']]);

        if (!$transaction) {
            return new Response('Transaction not found', 404);
        }

        $this->entityManager->beginTransaction();
        try {
            switch ($data['payment_status']) {
                case 'finished':
                    $this->handleSuccessfulPayment($transaction, $data);
                    break;
                    
                case 'expired':
                    $this->handleExpiredPayment($transaction);
                    break;
                    
                case 'failed':
                    $this->handleFailedPayment($transaction, $data);
                    break;
            }

            $this->entityManager->commit();
            return new Response('IPN processed');

        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logError('IPN processing failed', [
                'error' => $e->getMessage(),
                'payment_id' => $data['payment_id'] ?? null
            ]);
            return new Response('Processing error', 500);
        }
    }

    #[Route('/deposit/success', name: 'deposit_success')]
    public function depositSuccess(): Response
    {
        return $this->redirectWithFlash('success', 'Dépôt effectué avec succès');
    }

    #[Route('/deposit/cancel', name: 'deposit_cancel')]
    public function depositCancel(): Response
    {
        return $this->redirectWithFlash('warning', 'Dépôt annulé');
    }

    private function createNowPaymentsDeposit(string $currency, float $amount, int $userId, string $email): array
    {
        $response = $this->httpClient->post('https://api.nowpayments.io/v1/payment', [
            'headers' => [
                'x-api-key' => $_ENV['NOWPAYMENTS_API_KEY'],
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'price_amount' => $amount,
                'price_currency' => 'usd',
                'pay_currency' => $currency,
                'ipn_callback_url' => $this->generateUrl('nowpayments_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'order_id' => 'DEPO_'.$userId.'_'.time(),
                'customer_email' => $email,
                'success_url' => $this->generateUrl('deposit_success', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'cancel_url' => $this->generateUrl('deposit_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ],
            'timeout' => 15
        ]);

        $data = json_decode($response->getBody(), true);

        if (!isset($data['payment_id'])) {
            throw new \RuntimeException('NowPayments API error: '.($data['message'] ?? 'Unknown error'));
        }

        return $data;
    }

    private function checkNowPaymentsStatus(string $paymentId): string
    {
        $response = $this->httpClient->get("https://api.nowpayments.io/v1/payment/$paymentId", [
            'headers' => [
                'x-api-key' => $_ENV['NOWPAYMENTS_API_KEY']
            ],
            'timeout' => 10
        ]);

        $data = json_decode($response->getBody(), true);
        return $data['payment_status'] ?? 'pending';
    }

    private function handleSuccessfulPayment(Transactions $transaction, array $ipnData): void
    {
        if ($transaction->getStatus() === 'completed') {
            return;
        }

        $expectedAmount = $transaction->getAmount();
        $receivedAmount = (float)($ipnData['actually_paid'] ?? 0);
        
        if ($receivedAmount < $expectedAmount) {
            throw new \RuntimeException(sprintf(
                'Amount mismatch: expected %.2f, got %.2f',
                $expectedAmount,
                $receivedAmount
            ));
        }

        $user = $transaction->getUser();
        $user->setBalance($user->getBalance() + $expectedAmount);

        $transaction
            ->setStatus('completed')
            ->setVerifiedAt(new \DateTime())
            ->setMetadata(array_merge(
                $transaction->getMetadata() ?? [],
                ['ipn_data' => $ipnData]
            ));

        $this->sendTransactionEmail(
            $user->getEmail(),
            'Confirmation de dépôt',
            'emails/deposit_confirmed.html.twig',
            [
                'amount' => $expectedAmount,
                'currency' => str_replace('crypto_', '', $transaction->getMethod()),
                'tx_hash' => $ipnData['payin_hash'] ?? null
            ]
        );
    }

    private function handleExpiredPayment(Transactions $transaction): void
    {
        $transaction->setStatus('expired');
    }

    private function handleFailedPayment(Transactions $transaction, array $ipnData): void
    {
        $transaction
            ->setStatus('failed')
            ->setMetadata(array_merge(
                $transaction->getMetadata() ?? [],
                ['failure_reason' => $ipnData['payment_status'] ?? 'unknown']
            ));
    }

    private function sendDepositInstructionsEmail(
        string $email, 
        string $address, 
        float $amount, 
        string $currency,
        \DateTime $expiry,
        string $sourceAddress
    ): void {
        try {
            $this->mailer->send(
                (new TemplatedEmail())
                    ->to($email)
                    ->subject('[Important] Instructions pour votre dépôt en crypto')
                    ->htmlTemplate('emails/crypto_deposit_instructions.html.twig')
                    ->context([
                        'address' => $address,
                        'amount' => $amount,
                        'currency' => $currency,
                        'expiry' => $expiry,
                        'source_address' => $sourceAddress,
                        'qr_code' => $this->generateQrCodeUrl($address)
                    ])
            );
        } catch (\Exception $e) {
            $this->logError('Deposit email failed', ['error' => $e->getMessage()]);
        }
    }

    private function sendTransactionEmail(string $email, string $subject, string $template, array $context): void
    {
        try {
            $this->mailer->send(
                (new TemplatedEmail())
                    ->to($email)
                    ->subject($subject)
                    ->htmlTemplate($template)
                    ->context($context)
            );
        } catch (\Exception $e) {
            $this->logError('Transaction email failed', ['error' => $e->getMessage()]);
        }
    }

    private function generateQrCodeUrl(string $address): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='.urlencode($address);
    }

    private function validateWithdrawal(User $user, float $amount, string $currency, string $address): array
    {
        $errors = [];

        if ($amount < self::MIN_WITHDRAWAL_AMOUNT) {
            $errors[] = sprintf('Le montant minimum de retrait est de %.2f USD.', self::MIN_WITHDRAWAL_AMOUNT);
        }

        if ($amount > self::MAX_WITHDRAWAL_AMOUNT) {
            $errors[] = sprintf('Le montant maximum de retrait est de %.2f USD.', self::MAX_WITHDRAWAL_AMOUNT);
        }

        if (!array_key_exists($currency, self::SUPPORTED_CRYPTOS)) {
            $errors[] = 'Cryptomonnaie non supportée.';
        }

        if (!$this->validateCryptoAddress($currency, $address)) {
            $errors[] = 'Adresse de portefeuille invalide.';
        }

        $fees = $this->calculateWithdrawalFees($amount);
        $totalAmount = $amount + $fees;

        if ($totalAmount > $user->getBalance()) {
            $errors[] = 'Solde insuffisant pour ce retrait.';
        }

        return $errors;
    }

    private function validateDeposit(float $amount, string $method): array
    {
        $errors = [];

        if ($amount < self::MIN_DEPOSIT_AMOUNT) {
            $errors[] = sprintf('Le montant minimum de dépôt est de %.2f USD.', self::MIN_DEPOSIT_AMOUNT);
        }

        if ($amount > self::MAX_DEPOSIT_AMOUNT) {
            $errors[] = sprintf('Le montant maximum de dépôt est de %.2f USD.', self::MAX_DEPOSIT_AMOUNT);
        }

        if (!in_array($method, ['paypal', 'crypto'])) {
            $errors[] = 'Méthode de paiement invalide.';
        }

        return $errors;
    }

    private function validateCryptoAddress(string $currency, string $address): bool
    {
        return !empty($address) && strlen($address) >= 20;
    }

    private function processWithdrawal(Transactions $transaction, string $currency, string $address): void
    {
        // Implémentez ici la logique de retrait via votre service préféré
    }

    private function completeDeposit(Transactions $transaction): void
    {
        $this->entityManager->beginTransaction();
        try {
            $user = $transaction->getUser();
            $user->setBalance($user->getBalance() + $transaction->getAmount());

            $transaction
                ->setStatus('completed')
                ->setVerifiedAt(new DateTimeImmutable());

            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logError('Deposit completion failed', ['error' => $e->getMessage()]);
        }
    }

    private function getPayPalClient(): PayPalHttpClient
    {
        $clientId = $_ENV['PAYPAL_CLIENT_ID'];
        $clientSecret = $_ENV['PAYPAL_CLIENT_SECRET'];

        if (empty($clientId) || empty($clientSecret)) {
            throw new \RuntimeException('PayPal credentials not configured');
        }

        $environment = $_ENV['APP_ENV'] === 'prod'
            ? new ProductionEnvironment($clientId, $clientSecret)
            : new SandboxEnvironment($clientId, $clientSecret);

        return new PayPalHttpClient($environment);
    }

    private function redirectWithFlash(string $type, string $message): RedirectResponse
    {
        $this->addFlash($type, $message);
        return $this->redirectToRoute('app_profile');
    }

    private function logError(string $message, array $context = []): void
    {
        file_put_contents(
            $this->getParameter('kernel.logs_dir') . '/payment.log',
            date('[Y-m-d H:i:s]') . ' ERROR: ' . $message . ' ' . json_encode($context) . "\n",
            FILE_APPEND
        );
    }

    private function logInfo(string $message, array $context = []): void
    {
        file_put_contents(
            $this->getParameter('kernel.logs_dir') . '/payment.log',
            date('[Y-m-d H:i:s]') . ' INFO: ' . $message . ' ' . json_encode($context) . "\n",
            FILE_APPEND
        );
    }
}