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

class PaymentController extends AbstractController
{
    public const MIN_WITHDRAWAL_AMOUNT = 2.0;
    public const MIN_DEPOSIT_AMOUNT = 1.0;
    public const MAX_DEPOSIT_AMOUNT = 10000.0;
    public const MAX_WITHDRAWAL_AMOUNT = 5000.0;
    public const DEPOSIT_EXPIRATION_HOURS = 2;

    public const SUPPORTED_CRYPTOS = [
        'BNB' => 'Binance Coin (BNB)',
        'BUSD.BEP20' => 'BUSD Token (BSC Chain) - BEP20',
        'BTC' => 'Bitcoin (BTC)',
        'BCH' => 'Bitcoin Cash (BCH)',
        'ADA' => 'Cardano (ADA)',
        'DASH' => 'Dash (DASH)',
        'DOGE' => 'Dogecoin (DOGE)',
        'EOS' => 'EOS (EOS)',
        'ETH' => 'Ethereum (ETH)',
        'ETC' => 'Ethereum Classic (ETC)',
        'LTC' => 'Litecoin (LTC)',
        'XMR' => 'Monero (XMR)',
        'USDT.PRC20' => 'POLYGON (USDT.PRC20)',
        'XRP' => 'Ripple (XRP)',
        'XLM' => 'Stellar (XLM)',
        'TRX' => 'TRON (TRX)',
        'USDT.BEP20' => 'USDT (BEP20)',
        'USDT.ERC20' => 'USDT (ERC20)',
        'USDT.MATIC' => 'USDT (Polygon/MATIC)',
        'USDT.TON' => 'USDT (TON)',
        'USDT.TRC20' => 'USDT (TRC20)',
        'ZEC' => 'Zcash (ZEC)'
    ];

    public const BLOCKCHAIN_CONFIRMATIONS_REQUIRED = [
        'BTC' => 1,
        'ETH' => 1,
        'USDT.ERC20' => 1,
        'USDT.BEP20' => 1,
        'USDT.TRC20' => 1,
        'LTC' => 1,
        'DOGE' => 1,
        'BCH' => 1,
        'XRP' => 1,
        'TRX' => 1
    ];

    public const BLOCKCHAIN_EXPLORERS = [
        'BTC' => 'https://blockstream.info/api/tx/',
        'ETH' => 'https://api.etherscan.io/api?module=proxy&action=eth_getTransactionByHash&txhash=',
        'USDT.ERC20' => 'https://api.etherscan.io/api?module=account&action=tokentx&contractaddress=0xdac17f958d2ee523a2206206994597c13d831ec7&txhash=',
        'USDT.BEP20' => 'https://api.bscscan.com/api?module=transaction&action=gettxreceiptstatus&txhash=',
        'USDT.TRC20' => 'https://apilist.tronscan.org/api/transaction-info?hash=',
        'LTC' => 'https://api.blockcypher.com/v1/ltc/main/txs/',
        'DOGE' => 'https://api.blockcypher.com/v1/doge/main/txs/',
        'BCH' => 'https://api.blockchair.com/bitcoin-cash/raw/transaction/',
        'XRP' => 'https://data.xrplmeta.org/transaction/',
        'TRX' => 'https://apilist.tronscan.org/api/transaction-info?hash='
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private CsrfTokenManagerInterface $csrfTokenManager
    ) {}

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
        $currency = strtoupper(trim($request->request->get('currency')));
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
                $fees
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
        }paypal

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
            return $this->redirectToRoute('app_select_wallet', ['id' => $transaction->getId()]);
        }

        return $this->redirectToRoute('app_paypal_redirect', ['id' => $transaction->getId()]);
    }

    #[Route('/deposit/crypto/select-wallet/{id}', name: 'app_select_wallet')]
    public function selectWallet(int $id): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if (!$transaction || $transaction->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Transaction invalide');
        }

        return $this->render('payment/select_wallet.html.twig', [
            'transaction' => $transaction,
            'supportedCryptos' => self::SUPPORTED_CRYPTOS,
            'csrf_token' => $this->csrfTokenManager->getToken('select_wallet')->getValue()
        ]);
    }

    #[Route('/deposit/crypto/process/{id}', name: 'app_process_crypto_deposit', methods: ['POST'])]
    public function processCryptoDeposit(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $token = new CsrfToken('select_wallet', $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->redirectWithFlash('danger', 'Token CSRF invalide');
        }

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if (!$transaction || $transaction->getUser() !== $this->getUser()) {
            return $this->redirectWithFlash('danger', 'Transaction invalide');
        }

        $cryptoType = $request->request->get('crypto_type');
        if (!array_key_exists($cryptoType, self::SUPPORTED_CRYPTOS)) {
            return $this->redirectWithFlash('danger', 'Type de crypto non supporté');
        }

        $depositAddress = $this->generateDepositAddress($cryptoType);
        $expiresAt = (new \DateTime())->modify('+' . self::DEPOSIT_EXPIRATION_HOURS . ' hours');

        $transaction
            ->setMethod('crypto_' . $cryptoType)
            ->setExternalId($depositAddress)
            ->setExpiresAt($expiresAt);

        $this->entityManager->flush();

        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($depositAddress);

        return $this->render('payment/crypto_deposit.html.twig', [
            'transaction' => $transaction,
            'amount' => $transaction->getAmount(),
            'depositAddress' => $depositAddress,
            'expiresAt' => $expiresAt,
            'network' => self::SUPPORTED_CRYPTOS[$cryptoType],
            'qrCodeUrl' => $qrCodeUrl,
            'initialExpiration' => $expiresAt->getTimestamp() - time(),
            'csrf_token' => $this->csrfTokenManager->getToken('crypto_deposit')->getValue()
        ]);
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

        if ($transaction->getExpiresAt() < new \DateTime()) {
            $transaction->setStatus('expired');
            $this->entityManager->flush();
            return $this->json(['status' => 'expired']);
        }

        $isConfirmed = $this->verifyBlockchainDeposit($transaction->getExternalId(), $transaction->getAmount());

        if ($isConfirmed) {
            $this->completeDeposit($transaction);
            return $this->json(['status' => 'completed']);
        }

        return $this->json(['status' => 'pending']);
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

    #[Route('/crypto/confirm/{id}', name: 'app_crypto_confirm', methods: ['POST'])]
    public function confirmCryptoDeposit(int $id, Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if (!$transaction || $transaction->getUser() !== $this->getUser()) {
            return $this->redirectWithFlash('danger', 'Transaction invalide');
        }

        $cryptoType = strtoupper(trim($request->request->get('cryptoType')));
        $txHash = trim($request->request->get('txHash'));

        if (empty($cryptoType) || empty($txHash)) {
            return $this->redirectWithFlash('danger', 'Veuillez fournir tous les détails de la transaction');
        }

        if (!array_key_exists($cryptoType, self::SUPPORTED_CRYPTOS)) {
            return $this->redirectWithFlash('danger', 'Cryptomonnaie non supportée');
        }

        $verificationResult = $this->verifyBlockchainTransaction($cryptoType, $txHash, $transaction->getAmount());

        $this->entityManager->beginTransaction();
        try {
            if ($verificationResult['confirmed']) {
                $user = $transaction->getUser();
                $user->setBalance($user->getBalance() + $transaction->getAmount());

                $transaction
                    ->setMethod("crypto_" . $cryptoType)
                    ->setExternalId($txHash)
                    ->setStatus('completed');

                $message = 'Dépôt confirmé et crédité avec succès!';
            } else {
                $transaction
                    ->setMethod("crypto_" . $cryptoType)
                    ->setExternalId($txHash)
                    ->setStatus('pending');

                $message = 'Transaction reçue mais pas encore confirmée sur la blockchain. Votre solde sera crédité automatiquement une fois confirmée.';
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->redirectWithFlash(
                $verificationResult['confirmed'] ? 'success' : 'warning',
                $message
            );
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logError('Crypto deposit failed', ['error' => $e->getMessage()]);
            return $this->redirectWithFlash('danger', 'Erreur lors du traitement: ' . $e->getMessage());
        }
    }

    #[Route('/crypto/check-pending', name: 'app_check_pending')]
    public function checkPendingTransactions(): Response
    {
        $pendingTransactions = $this->entityManager->getRepository(Transactions::class)
            ->findBy(['status' => 'pending', 'method' => ['like' => 'crypto_%']]);

        foreach ($pendingTransactions as $transaction) {
            $method = $transaction->getMethod();
            $cryptoType = str_replace('crypto_', '', $method);

            if (array_key_exists($cryptoType, self::SUPPORTED_CRYPTOS)) {
                $verification = $this->verifyBlockchainTransaction(
                    $cryptoType,
                    $transaction->getExternalId(),
                    $transaction->getAmount()
                );

                if ($verification['confirmed']) {
                    $user = $transaction->getUser();
                    $user->setBalance($user->getBalance() + $transaction->getAmount());
                    $transaction->setStatus('completed');
                    $this->logInfo('Transaction confirmed', ['id' => $transaction->getId()]);
                }
            }
        }

        $this->entityManager->flush();
        return new Response('Pending transactions checked: ' . count($pendingTransactions));
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

    private function verifyBlockchainTransaction(string $cryptoType, string $txHash, float $amount): array
    {
        $client = new \GuzzleHttp\Client(['timeout' => 10]);

        try {
            $explorerUrl = self::BLOCKCHAIN_EXPLORERS[$cryptoType] ?? null;

            if (!$explorerUrl) {
                return ['confirmed' => false, 'confirmations' => 0];
            }

            $response = $client->get($explorerUrl . $txHash);
            $data = json_decode($response->getBody(), true);

            switch ($cryptoType) {
                case 'BTC':
                    $confirmations = $data['confirmations'] ?? 0;
                    $confirmed = $confirmations >= self::BLOCKCHAIN_CONFIRMATIONS_REQUIRED['BTC'];
                    $amountReceived = isset($data['value']) ? ($data['value'] / 100000000) : 0;
                    break;

                case 'ETH':
                    $blockNumber = hexdec($data['result']['blockNumber'] ?? '0x0');
                    $currentBlockResponse = $client->get('https://api.etherscan.io/api?module=proxy&action=eth_blockNumber');
                    $currentBlockData = json_decode($currentBlockResponse->getBody(), true);
                    $currentBlock = hexdec($currentBlockData['result'] ?? '0x0');

                    $confirmations = $currentBlock - $blockNumber;
                    $confirmed = $confirmations >= self::BLOCKCHAIN_CONFIRMATIONS_REQUIRED['ETH'];
                    $amountReceived = isset($data['result']['value'])
                        ? (hexdec($data['result']['value']) / 1000000000000000000)
                        : 0;
                    break;

                case 'USDT.ERC20':
                    $amountReceived = 0;
                    if (!empty($data['result'][0]['value'])) {
                        $decimals = (int)($data['result'][0]['tokenDecimal'] ?? 6);
                        $amountReceived = (float)($data['result'][0]['value'] / pow(10, $decimals));
                    }
                    $confirmed = $amountReceived >= $amount;
                    $confirmations = $confirmed ? self::BLOCKCHAIN_CONFIRMATIONS_REQUIRED['USDT.ERC20'] : 0;
                    break;

                case 'USDT.BEP20':
                    $status = $data['result']['status'] ?? '0';
                    $confirmed = $status === '1';
                    $confirmations = $confirmed ? self::BLOCKCHAIN_CONFIRMATIONS_REQUIRED['USDT.BEP20'] : 0;
                    $amountReceived = $confirmed ? $amount : 0;
                    break;

                case 'USDT.TRC20':
                    $confirmed = isset($data['contractRet']) && $data['contractRet'] === 'SUCCESS';
                    $amountReceived = isset($data['amount']) ? ($data['amount'] / 1000000) : 0;
                    $confirmations = $confirmed ? self::BLOCKCHAIN_CONFIRMATIONS_REQUIRED['USDT.TRC20'] : 0;
                    break;

                default:
                    return ['confirmed' => false, 'confirmations' => 0];
            }

            return [
                'confirmed' => $confirmed && $amountReceived >= $amount,
                'confirmations' => $confirmations
            ];
        } catch (\Exception $e) {
            return ['confirmed' => false, 'confirmations' => 0];
        }
    }

    private function validateCryptoAddress(string $currency, string $address): bool
    {
        return true;
    }

    private function generateDepositAddress(string $currency): string
    {
        $prefixes = [
            'BTC' => '1',
            'ETH' => '0x',
            'USDT.ERC20' => '0x',
            'USDT.TRC20' => 'T',
            'LTC' => 'L',
            'DOGE' => 'D',
            'BCH' => 'q',
            'XRP' => 'r',
            'TRX' => 'T'
        ];

        $prefix = $prefixes[$currency] ?? 'T';
        $randomPart = bin2hex(random_bytes(16));
        
        switch ($currency) {
            case 'BTC':
            case 'LTC':
            case 'DOGE':
            case 'BCH':
                return $prefix . substr($randomPart, 0, 33);
            case 'ETH':
            case 'USDT.ERC20':
                return $prefix . substr($randomPart, 0, 40);
            case 'XRP':
                return $prefix . substr($randomPart, 0, 33);
            default:
                return $prefix . substr($randomPart, 0, 34);
        }
    }

    private function verifyBlockchainDeposit(string $address, float $amount): bool
    {
        return false;
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

    private function processWithdrawal(Transactions $transaction, string $currency, string $address): void
    {
        // Implémentation avec votre service de retrait
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