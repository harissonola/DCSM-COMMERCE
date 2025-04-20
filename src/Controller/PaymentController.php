<?php

namespace App\Controller;

use Symfony\Component\Mime\Address;
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
        // Top 10 cryptos (par capitalisation)
        'btc' => 'Bitcoin (BTC)',
        'eth' => 'Ethereum (ETH)',
        'usdt' => 'Tether (USDT)', // Spécifiez le réseau après
        'bnb' => 'Binance Coin (BNB)',
        'sol' => 'Solana (SOL)',
        'usdc' => 'USD Coin (USDC)',
        'xrp' => 'XRP (XRP)',
        'ada' => 'Cardano (ADA)',
        'avax' => 'Avalanche (AVAX)',
        'doge' => 'Dogecoin (DOGE)',
    
        // Stablecoins USDT avec réseaux (vérifiés)
        'usdt_erc20' => 'Tether (USDT-ERC20)',
        'usdt_trc20' => 'Tether (USDT-TRC20)',
        'usdt_bep20' => 'Tether (USDT-BEP20)',
        'usdt_polygon' => 'Tether (USDT-Polygon)',
        
        // Autres cryptos importantes
        'shib' => 'Shiba Inu (SHIB)',
        'dot' => 'Polkadot (DOT)',
        'ltc' => 'Litecoin (LTC)',
        'bch' => 'Bitcoin Cash (BCH)',
        'trx' => 'TRON (TRX)',
        'matic' => 'Polygon (MATIC)',
        'ton' => 'Toncoin (TON)',
        
        // Nouveaux ajouts pertinents (2024)
        'link' => 'Chainlink (LINK)',
        'xlm' => 'Stellar (XLM)',
        'uni' => 'Uniswap (UNI)',
        'atom' => 'Cosmos (ATOM)'
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
            $this->addFlash('danger', 'Token CSRF invalide');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        $amount = (float)$request->request->get('amount');
        $currency = strtolower(trim($request->request->get('currency')));
        $address = trim($request->request->get('address'));

        $errors = $this->validateWithdrawal($user, $amount, $currency, $address);
        if (!empty($errors)) {
            $this->addFlash('danger', $errors[0]);
            return $this->redirectToRoute('app_profile');
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
            $this->addFlash('success', sprintf(
                'Demande de retrait de %.2f USD enregistrée. Frais: %.2f USD',
                $amount,
                $fees
            ));
            return $this->redirectToRoute('app_profile');
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logError('Withdrawal failed', ['error' => $e->getMessage()]);
            $this->addFlash('danger', 'Erreur lors du traitement du retrait');
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/deposit', name: 'app_deposit', methods: ['POST'])]
    public function deposit(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $token = new CsrfToken('deposit', $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            $this->addFlash('danger', 'Token CSRF invalide');
            return $this->redirectToRoute('app_profile');
        }

        $amount = (float)$request->request->get('amount');
        $method = $request->request->get('method');

        $errors = $this->validateDeposit($amount, $method);
        if (!empty($errors)) {
            $this->addFlash('danger', $errors[0]);
            return $this->redirectToRoute('app_profile');
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
            $this->addFlash('danger', 'Token CSRF invalide');
            return $this->redirectToRoute('app_profile');
        }

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if (!$transaction || $transaction->getUser() !== $this->getUser()) {
            $this->addFlash('danger', 'Transaction invalide');
            return $this->redirectToRoute('app_profile');
        }

        $cryptoType = strtolower($request->request->get('crypto_type'));
        $sourceAddress = trim($request->request->get('source_address'));

        if (!array_key_exists($cryptoType, self::SUPPORTED_CRYPTOS)) {
            $this->addFlash('danger', 'Type de crypto non supporté');
            return $this->redirectToRoute('app_profile');
        }

        if (empty($sourceAddress)) {
            $this->addFlash('danger', 'Veuillez fournir votre adresse source');
            return $this->redirectToRoute('app_profile');
        }

        try {
            $depositData = $this->createNowPaymentsDeposit(
                $cryptoType,
                $transaction->getAmount(),
                $transaction->getId(),
                $transaction->getUser()->getEmail()
            );

            // Vérification des données requises
            if (!isset($depositData['payment_id']) || !isset($depositData['pay_address'])) {
                throw new \RuntimeException('Réponse NowPayments incomplète');
            }

            // Gestion de la date d'expiration
            $expiryDate = isset($depositData['expiry_estimated_date']) 
                ? new \DateTime($depositData['expiry_estimated_date'])
                : new \DateTime('+2 hours');

            $transaction
                ->setMethod('crypto_' . $cryptoType)
                ->setExternalId($depositData['payment_id'])
                ->setExpiresAt($expiryDate)
                ->setMetadata([
                    'np_address' => $depositData['pay_address'],
                    'np_id' => $depositData['payment_id'],
                    'np_expiry' => $expiryDate->format('Y-m-d H:i:s'),
                    'source_address' => $sourceAddress
                ]);

            $this->entityManager->flush();

            $this->sendDepositInstructionsEmail(
                $transaction->getUser()->getEmail(),
                $depositData['pay_address'],
                $transaction->getAmount(),
                $cryptoType,
                $expiryDate,
                $sourceAddress
            );

            return $this->redirectToRoute('app_crypto_deposit_instructions', [
                'id' => $transaction->getId(),
                'payment_id' => $depositData['payment_id']
            ]);
        } catch (\Exception $e) {
            $this->logError('NowPayments deposit failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            $this->addFlash('danger', 'Erreur lors de la création du dépôt: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/deposit/crypto/instructions/{id}/{payment_id}', name: 'app_crypto_deposit_instructions')]
    public function cryptoDepositInstructions(int $id, string $payment_id): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if (!$transaction || $transaction->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Transaction invalide');
        }

        if ($transaction->getExternalId() !== $payment_id) {
            throw $this->createAccessDeniedException('ID de paiement invalide');
        }

        $metadata = $transaction->getMetadata();
        $cryptoType = str_replace('crypto_', '', $transaction->getMethod());

        return $this->render('payment/crypto_deposit_instructions.html.twig', [
            'transaction' => $transaction,
            'amount' => $transaction->getAmount(),
            'depositAddress' => $metadata['np_address'],
            'sourceAddress' => $metadata['source_address'],
            'expiresAt' => $transaction->getExpiresAt(),
            'network' => self::SUPPORTED_CRYPTOS[$cryptoType],
            'qrCodeUrl' => $this->generateQrCodeUrl($metadata['np_address']),
            'initialExpiration' => $transaction->getExpiresAt()->getTimestamp() - time(),
            'csrf_token' => $this->csrfTokenManager->getToken('crypto_deposit')->getValue()
        ]);
    }

    #[Route('/deposit/success', name: 'deposit_success')]
    public function depositSuccess(): Response
    {
        $this->addFlash('success', 'Dépôt effectué avec succès');
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/deposit/cancel', name: 'deposit_cancel')]
    public function depositCancel(): Response
    {
        $this->addFlash('warning', 'Dépôt annulé');
        return $this->redirectToRoute('app_profile');
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
            $this->logError('IPN processing failed', ['error' => $e->getMessage()]);
            return new Response('Processing error', 500);
        }
    }

    private function createNowPaymentsDeposit(
        string $currency,
        float $amount,
        int $transactionId,
        string $email
    ): array {
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
                'order_id' => 'DEPO_' . $transactionId . '_' . time(),
                'customer_email' => $email
            ],
            'timeout' => 15
        ]);

        $data = json_decode($response->getBody(), true);

        if (!isset($data['payment_id'])) {
            throw new \RuntimeException('NowPayments API error: ' . ($data['message'] ?? 'Unknown error'));
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
            $email = (new TemplatedEmail())
                ->from(new Address('no-reply@bictrary.com', 'Bictrary'))
                ->to($email)
                ->subject('[Important] Instructions pour votre dépôt en crypto')
                ->htmlTemplate('emails/crypto_deposit_instructions.html.twig')
                ->context([
                    'address' => $address,
                    'amount' => $amount,
                    'currency' => $currency,
                    'expiry' => $expiry,
                    'source_address' => $sourceAddress,
                    'qr_code' => $this->generateQrCodeUrl($address),
                    'app_name' => $this->getParameter('app.site_name')
                ]);

            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logError('Deposit email failed', ['error' => $e->getMessage()]);
        }
    }

    private function sendTransactionEmail(
        string $email,
        string $subject,
        string $template,
        array $context
    ): void {
        try {
            $email = (new TemplatedEmail())
                ->to($email)
                ->subject($subject)
                ->htmlTemplate($template)
                ->context($context);

            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logError('Transaction email failed', ['error' => $e->getMessage()]);
        }
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

    private function validateCryptoAddress(string $currency, string $address): bool
    {
        return !empty($address) && strlen($address) >= 20;
    }

    private function processWithdrawal(Transactions $transaction, string $currency, string $address): void
    {
        $this->logInfo('Withdrawal processed', [
            'transaction_id' => $transaction->getId(),
            'amount' => $transaction->getAmount(),
            'currency' => $currency,
            'address' => $address
        ]);
    }

    private function generateQrCodeUrl(string $address): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($address);
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
            throw $e;
        }
    }

    private function logInfo(string $message, array $context = []): void
    {
        file_put_contents(
            $this->getParameter('kernel.logs_dir') . '/payment_info.log',
            date('[Y-m-d H:i:s]') . ' INFO: ' . $message . ' ' . json_encode($context) . "\n",
            FILE_APPEND
        );
    }

    private function logError(string $message, array $context = []): void
    {
        file_put_contents(
            $this->getParameter('kernel.logs_dir') . '/payment_errors.log',
            date('[Y-m-d H:i:s]') . ' ERROR: ' . $message . ' ' . json_encode($context) . "\n",
            FILE_APPEND
        );
    }
}