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
    public const PAYMENT_TOLERANCE = 0.05;

    public const SUPPORTED_CRYPTOS = [
        'ada' => 'Cardano (ADA)',
        'atom' => 'Cosmos (ATOM)',
        'avax' => 'Avalanche (AVAX)',
        'axs' => 'Axie Infinity (AXS)',
        'bch' => 'Bitcoin Cash (BCH)',
        'bnb' => 'Binance Coin (BNB)',
        'bonk' => 'Bonk (BONK)',
        'btc' => 'Bitcoin (BTC)',
        'busdbsc' => 'Binance USD (BUSD-BEP20)',
        'dai' => 'Dai (DAI)',
        'doge' => 'Dogecoin (DOGE)',
        'dot' => 'Polkadot (DOT)',
        'egld' => 'Elrond (EGLD)',
        'eth' => 'Ethereum (ETH)',
        'fil' => 'Filecoin (FIL)',
        'gala' => 'Gala (GALA)',
        'hbar' => 'Hedera (HBAR)',
        'link' => 'Chainlink (LINK)',
        'ltc' => 'Litecoin (LTC)',
        'mana' => 'Decentraland (MANA)',
        'matic' => 'Polygon (MATIC)',
        'pepe' => 'Pepe (PEPE)',
        'sand' => 'The Sandbox (SAND)',
        'sei' => 'Sei (SEI)',
        'shib' => 'Shiba Inu (SHIB)',
        'sol' => 'Solana (SOL)',
        'sui' => 'Sui (SUI)',
        'ton' => 'Toncoin (TON)',
        'trx' => 'TRON (TRX)',
        'tusd' => 'TrueUSD (TUSD)',
        'uni' => 'Uniswap (UNI)',
        'usdc' => 'USD Coin (USDC)',
        'usdcbsc' => 'USD Coin (USDC-BEP20)',
        'usdcmatic' => 'USD Coin (USDC-Polygon)',
        'usdterc20' => 'Tether (USDT-ERC20)',
        'usdtmatic' => 'Tether (USDT-Polygon)',
        'usdtsol' => 'Tether (USDT-Solana)',
        'usdttrc20' => 'Tether (USDT-TRC20)',
        'usdtarb' => 'Tether (USDT-Arbitrum)',
        'usdtbsc' => 'Tether (USDT-BEP20)',
        'vet' => 'VeChain (VET)',
        'xlm' => 'Stellar (XLM)',
        'xrp' => 'XRP (XRP)',
        'xtz' => 'Tezos (XTZ)'
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

            $payoutResponse = $this->httpClient->post('https://api.nowpayments.io/v1/payout', [
                'headers' => [
                    'x-api-key' => $_ENV['NOWPAYMENTS_API_KEY'],
                    'Content-Type' => 'application/json'
                ],
                'json' => [
                    'withdrawal_amount' => $amount,
                    'withdrawal_currency' => $currency,
                    'address' => $address,
                    'unique_external_id' => 'WITHDRAW_' . $transaction->getId(),
                    'ipn_callback_url' => $this->generateUrl('nowpayments_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL)
                ],
                'timeout' => 15
            ]);

            $data = json_decode($payoutResponse->getBody(), true);

            if (!isset($data['payout_id'])) {
                throw new \RuntimeException($data['message'] ?? 'Erreur API NowPayments');
            }

            $transaction
                ->setExternalId($data['payout_id'])
                ->setMetadata([
                    'np_response' => $data,
                    'wallet_address' => $address
                ]);

            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->addFlash('success', 'Votre demande de retrait a été enregistrée avec succès. Le traitement peut prendre quelques heures.');
            return $this->redirectToRoute('app_profile');

        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->addFlash('danger', 'Une erreur est survenue lors du traitement de votre retrait. Veuillez réessayer.');
            $this->logError('Withdrawal failed', ['error' => $e->getMessage()]);
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

            if (!isset($depositData['payment_id']) || !isset($depositData['pay_address'])) {
                throw new \RuntimeException('Réponse NowPayments incomplète');
            }

            $expiryDate = isset($depositData['expiry_estimated_date'])
                ? new \DateTime($depositData['expiry_estimated_date'])
                : new \DateTime('+' . self::DEPOSIT_EXPIRATION_HOURS . ' hours');

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
            return $this->json([
                'status' => 'completed',
                'expires_at' => $transaction->getExpiresAt()->format('c'),
                'new_balance' => $transaction->getUser()->getBalance()
            ]);
        }

        try {
            $paymentStatus = $this->checkNowPaymentsStatus($transaction->getExternalId());

            if ($paymentStatus === 'finished') {
                $this->entityManager->beginTransaction();
                try {
                    $user = $transaction->getUser();
                    $user->setBalance($user->getBalance() + $transaction->getAmount());

                    $transaction->setStatus('completed')
                        ->setVerifiedAt(new \DateTime());

                    $this->entityManager->flush();
                    $this->entityManager->commit();

                    return $this->json([
                        'status' => 'completed',
                        'expires_at' => $transaction->getExpiresAt()->format('c'),
                        'new_balance' => $user->getBalance()
                    ]);
                } catch (\Exception $e) {
                    $this->entityManager->rollback();
                    throw $e;
                }
            } elseif ($paymentStatus === 'expired') {
                if ($transaction->getStatus() !== 'expired') {
                    $transaction->setStatus('expired');
                    $this->entityManager->flush();
                }
                return $this->json([
                    'status' => 'expired',
                    'expires_at' => $transaction->getExpiresAt()->format('c')
                ]);
            }

            return $this->json([
                'status' => 'pending',
                'expires_at' => $transaction->getExpiresAt()->format('c')
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'expires_at' => $transaction->getExpiresAt()->format('c')
            ], 500);
        }
    }

    #[Route('/nowpayments/ipn', name: 'nowpayments_ipn', methods: ['POST', 'OPTIONS'])]
    public function handleNowPaymentsIPN(Request $request): Response
    {
        $ipnLogPath = $this->getParameter('kernel.logs_dir') . '/ipn.log';
        $now = new \DateTime();
        
        file_put_contents($ipnLogPath, "\n[{$now->format('Y-m-d H:i:s')}] IPN Received\n", FILE_APPEND);
        file_put_contents($ipnLogPath, "Headers: ".json_encode($request->headers->all())."\n", FILE_APPEND);

        if ($request->getMethod() === 'OPTIONS') {
            return new Response('', 204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'x-nowpayments-sig, Content-Type'
            ]);
        }

        $receivedSignature = $request->headers->get('x-nowpayments-sig');
        $payload = $request->getContent();
        $expectedSignature = hash_hmac('sha512', $payload, $_ENV['NOWPAYMENTS_IPN_SECRET']);

        if (!hash_equals($expectedSignature, $receivedSignature)) {
            file_put_contents($ipnLogPath, "ERROR: Invalid HMAC\n", FILE_APPEND);
            return new JsonResponse(['error' => 'Invalid signature'], 403);
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            file_put_contents($ipnLogPath, "Payload: ".print_r($data, true)."\n", FILE_APPEND);

            if (isset($data['payout_id'])) {
                return $this->handlePayoutIPN($data);
            } elseif (isset($data['payment_id'])) {
                return $this->handleDepositIPN($data);
            }

            throw new \RuntimeException("Type de transaction IPN non reconnu");

        } catch (\Exception $e) {
            file_put_contents($ipnLogPath, "ERROR: ".$e->getMessage()."\n".$e->getTraceAsString()."\n", FILE_APPEND);
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    private function handleDepositIPN(array $data): JsonResponse
    {
        $requiredFields = ['payment_id', 'payment_status', 'actually_paid', 'pay_currency', 'order_id'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new \RuntimeException("Champ manquant pour dépôt: $field");
            }
        }

        if (!preg_match('/^DEPO_(\d+)_/', $data['order_id'], $matches)) {
            throw new \RuntimeException("Format order_id invalide pour dépôt");
        }

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($matches[1]);
        if (!$transaction || $transaction->getType() !== 'deposit') {
            throw new \RuntimeException("Transaction de dépôt introuvable");
        }

        $expectedCurrency = str_replace('crypto_', '', $transaction->getMethod());
        if (strtolower($data['pay_currency']) !== strtolower($expectedCurrency)) {
            throw new \RuntimeException(sprintf(
                "Devise mismatch: attendu %s, reçu %s",
                $expectedCurrency,
                $data['pay_currency']
            ));
        }

        switch ($data['payment_status']) {
            case 'finished':
                $this->handleSuccessfulPayment($transaction, $data);
                break;

            case 'partially_paid':
                $this->handlePartialPayment($transaction, $data);
                break;

            case 'failed':
                $this->handleFailedPayment($transaction, $data);
                break;

            case 'expired':
                $this->handleExpiredPayment($transaction);
                break;

            default:
                throw new \RuntimeException("Statut de paiement non géré: {$data['payment_status']}");
        }

        return new JsonResponse(['status' => 'success']);
    }

    private function handlePayoutIPN(array $data): JsonResponse
    {
        $requiredFields = ['payout_id', 'withdrawal_status', 'withdrawal_amount', 'withdrawal_currency', 'unique_external_id'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                throw new \RuntimeException("Champ manquant pour retrait: $field");
            }
        }

        if (!preg_match('/^WITHDRAW_(\d+)$/', $data['unique_external_id'], $matches)) {
            throw new \RuntimeException("Format unique_external_id invalide");
        }

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($matches[1]);
        if (!$transaction || $transaction->getType() !== 'withdrawal') {
            throw new \RuntimeException("Transaction de retrait introuvable");
        }

        $user = $transaction->getUser();
        $session = $this->getSession();

        $this->entityManager->beginTransaction();
        try {
            switch ($data['withdrawal_status']) {
                case 'finished':
                    $transaction
                        ->setStatus('completed')
                        ->setVerifiedAt(new \DateTime())
                        ->setMetadata(array_merge(
                            $transaction->getMetadata() ?? [],
                            [
                                'ipn_data' => $data,
                                'tx_hash' => $data['txid'] ?? null,
                                'completed_at' => $data['completed_at'] ?? null
                            ]
                        ));
                    
                    $this->sendWithdrawalEmail($user, $transaction, $data);
                    $session->getFlashBag()->add('success', 'Votre retrait a été traité avec succès.');
                    break;

                case 'failed':
                    $totalAmount = $transaction->getAmount() + $transaction->getFees();
                    $user->setBalance($user->getBalance() + $totalAmount);
                    
                    $transaction
                        ->setStatus('failed')
                        ->setMetadata(array_merge(
                            $transaction->getMetadata() ?? [],
                            [
                                'error_reason' => $data['error_message'] ?? 'Unknown error',
                                'refunded' => true
                            ]
                        ));
                    $session->getFlashBag()->add('warning', 'Le retrait a échoué. Les fonds ont été remboursés sur votre compte.');
                    break;

                case 'processing':
                    $transaction->setStatus('processing');
                    $session->getFlashBag()->add('info', 'Votre retrait est en cours de traitement.');
                    break;

                default:
                    throw new \RuntimeException("Statut de retrait non géré: {$data['withdrawal_status']}");
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return new JsonResponse(['status' => 'success']);

        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $session->getFlashBag()->add('danger', 'Une erreur est survenue lors du traitement de votre retrait.');
            throw $e;
        }
    }

    private function handleSuccessfulPayment(Transactions $transaction, array $ipnData): void
    {
        if ($transaction->getStatus() === 'completed') {
            return;
        }

        $expectedAmount = $transaction->getAmount();
        $receivedAmount = (float)($ipnData['actually_paid'] ?? 0);
        $minAcceptedAmount = $expectedAmount * (1 - self::PAYMENT_TOLERANCE);

        if ($receivedAmount < $minAcceptedAmount) {
            $this->logError('Montant insuffisant', [
                'expected' => $expectedAmount,
                'received' => $receivedAmount,
                'min_accepted' => $minAcceptedAmount
            ]);
            throw new \RuntimeException(sprintf(
                'Montant insuffisant: attendu %.2f USD (min %.2f), reçu %.2f USD',
                $expectedAmount,
                $minAcceptedAmount,
                $receivedAmount
            ));
        }

        $this->entityManager->beginTransaction();
        try {
            $user = $transaction->getUser();
            $newBalance = $user->getBalance() + $expectedAmount;
            $user->setBalance($newBalance);

            $transaction
                ->setStatus('completed')
                ->setVerifiedAt(new \DateTime())
                ->setMetadata(array_merge(
                    $transaction->getMetadata() ?? [],
                    [
                        'ipn_data' => $ipnData,
                        'tx_hash' => $ipnData['payin_hash'] ?? null,
                        'actually_paid' => $receivedAmount,
                        'credited_amount' => $expectedAmount,
                        'new_balance' => $newBalance
                    ]
                ));

            $this->sendConfirmationEmail($user, $transaction, $ipnData);
            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->getSession()->getFlashBag()->add('success', 'Votre dépôt a été crédité avec succès.');

        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->getSession()->getFlashBag()->add('danger', 'Erreur lors du traitement de votre dépôt.');
            throw $e;
        }
    }

    private function handlePartialPayment(Transactions $transaction, array $ipnData): void
    {
        $this->entityManager->beginTransaction();
        try {
            $receivedAmount = (float)$ipnData['actually_paid'];

            $transaction
                ->setStatus('partially_paid')
                ->setMetadata(array_merge(
                    $transaction->getMetadata() ?? [],
                    [
                        'ipn_data' => $ipnData,
                        'actually_paid' => $receivedAmount,
                        'remaining_amount' => $transaction->getAmount() - $receivedAmount
                    ]
                ));

            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->getSession()->getFlashBag()->add('warning', 
                sprintf('Paiement partiel reçu (%.2f/%2.f USD).', $receivedAmount, $transaction->getAmount())
            );

        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    private function handleExpiredPayment(Transactions $transaction): void
    {
        if ($transaction->getStatus() !== 'expired') {
            $transaction->setStatus('expired');
            $this->entityManager->flush();
            $this->getSession()->getFlashBag()->add('warning', 'Le délai pour effectuer le dépôt a expiré.');
        }
    }

    private function handleFailedPayment(Transactions $transaction, array $ipnData): void
    {
        $transaction
            ->setStatus('failed')
            ->setMetadata(array_merge(
                $transaction->getMetadata() ?? [],
                ['failure_reason' => $ipnData['payment_status'] ?? 'unknown']
            ));
        $this->entityManager->flush();
        $this->getSession()->getFlashBag()->add('danger', 'Le paiement a échoué.');
    }

    private function sendWithdrawalEmail(User $user, Transactions $transaction, array $ipnData): void
    {
        try {
            $email = (new TemplatedEmail())
                ->from(new Address('no-reply@bictrary.com', 'Bictrary'))
                ->to($user->getEmail())
                ->subject('Confirmation de retrait')
                ->htmlTemplate('emails/withdrawal_confirmed.html.twig')
                ->context([
                    'amount' => $transaction->getAmount(),
                    'currency' => str_replace('crypto_', '', $transaction->getMethod()),
                    'fees' => $transaction->getFees(),
                    'net_amount' => $transaction->getAmount() - $transaction->getFees(),
                    'tx_hash' => $ipnData['txid'] ?? null,
                    'wallet_address' => $transaction->getExternalId()
                ]);

            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logError('Withdrawal email failed', ['error' => $e->getMessage()]);
        }
    }

    private function sendConfirmationEmail(User $user, Transactions $transaction, array $ipnData): void
    {
        try {
            $email = (new TemplatedEmail())
                ->from(new Address('no-reply@bictrary.com', 'Bictrary'))
                ->to($user->getEmail())
                ->subject('Confirmation de dépôt')
                ->htmlTemplate('emails/deposit_confirmed.html.twig')
                ->context([
                    'amount' => $transaction->getAmount(),
                    'currency' => str_replace('crypto_', '', $transaction->getMethod()),
                    'tx_hash' => $ipnData['payin_hash'] ?? null,
                    'date' => new \DateTime(),
                    'received_amount' => $ipnData['actually_paid'] ?? $transaction->getAmount()
                ]);

            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logError('Deposit confirmation email failed', ['error' => $e->getMessage()]);
        }
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

    private function generateQrCodeUrl(string $address): string
    {
        return 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($address);
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