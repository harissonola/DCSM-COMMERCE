<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Transactions;
use App\Entity\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;
use Tron\Api;
use Tron\TRX;
use Tron\Exceptions\TronErrorException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PaymentController extends AbstractController
{
    private $entityManager;
    private $cache;
    private $coinGeckoClient;
    private $tronGridClient;
    private $logger;
    private $tron;
    private $rateLimiter;
    private $validator;

    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        RateLimiterFactory $paymentRateLimiter,
        ValidatorInterface $validator
    ) {
        $this->entityManager = $entityManager;
        $this->logger = $logger;
        $this->cache = new FilesystemAdapter();
        $this->rateLimiter = $paymentRateLimiter;
        $this->validator = $validator;

        $this->initializeApiClients();
        $this->initializeTron();
    }

    private function initializeApiClients(): void
    {
        try {
            $this->coinGeckoClient = new Client([
                'base_uri' => 'https://api.coingecko.com/api/v3/',
                'timeout' => 5,
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json'
                ],
                'verify' => true
            ]);

            $this->tronGridClient = new Client([
                'base_uri' => 'https://api.trongrid.io/',
                'headers' => [
                    'TRON-PRO-API-KEY' => $_ENV['TRONGRID_API_KEY'],
                    'Content-Type' => 'application/json'
                ],
                'verify' => true
            ]);
        } catch (\Exception $e) {
            $this->logger->critical('API Client initialization failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Service temporarily unavailable');
        }
    }

    private function initializeTron(): void
    {
        try {
            $this->tron = new TRX(new Api($_ENV['TRONGRID_API_KEY']));
            $this->tron->setPrivateKey($_ENV['TRON_WALLET_PRIVATE_KEY']);
        } catch (TronErrorException $e) {
            $this->logger->critical('TRON initialization failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('TRON service unavailable');
        }
    }

    #[Route('/deposit/crypto', name: 'app_crypto_deposit', methods: ['POST'])]
    public function cryptoDeposit(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // Rate limiting
        $limiter = $this->rateLimiter->create($request->getClientIp());
        if (false === $limiter->consume(1)->isAccepted()) {
            $this->addFlash('danger', 'Trop de tentatives. Veuillez réessayer plus tard.');
            return $this->redirectToRoute('app_profile');
        }

        // CSRF protection
        if (!$this->isCsrfTokenValid('crypto-deposit', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        $amount = (float)$request->request->get('amount');
        $cryptoType = strtoupper($request->request->get('cryptoType'));

        // Input validation
        $constraints = new Assert\Collection([
            'amount' => [
                new Assert\NotBlank(),
                new Assert\Type(['type' => 'numeric']),
                new Assert\Range(['min' => 1, 'max' => 10000])
            ],
            'cryptoType' => [
                new Assert\NotBlank(),
                new Assert\Choice(['choices' => ['BTC', 'ETH', 'TRX', 'USDT', 'USDT.TRC20', 'BNB', 'XRP']])
            ]
        ]);

        $violations = $this->validator->validate([
            'amount' => $amount,
            'cryptoType' => $cryptoType
        ], $constraints);

        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $this->addFlash('danger', $violation->getMessage());
            }
            return $this->redirectToRoute('app_profile');
        }

        try {
            $rate = $this->getCurrentRate($cryptoType);
            if ($rate <= 0) {
                throw new \Exception('Invalid exchange rate');
            }

            $transaction = (new Transactions())
                ->setUser($user)
                ->setAmount($amount)
                ->setType('deposit')
                ->setMethod('crypto_' . $cryptoType)
                ->setStatus('pending')
                ->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($transaction);
            $this->entityManager->flush();

            $depositAddress = $this->generateSecureDepositAddress($cryptoType, $transaction);

            return $this->render('payment/deposit_redirect.html.twig', [
                'address' => $depositAddress,
                'cryptoType' => $cryptoType,
                'amountUSD' => $amount,
                'transactionId' => $transaction->getId(),
                'qrCodeData' => "$cryptoType:$depositAddress?amount=$amount",
                'expiresAt' => (new \DateTime('+15 minutes'))->format('Y-m-d H:i:s'),
                'rate' => $rate
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Crypto deposit failed', [
                'error' => $e->getMessage(),
                'user' => $user->getId(),
                'amount' => $amount,
                'crypto' => $cryptoType
            ]);
            $this->addFlash('danger', 'Erreur lors du traitement. Contactez le support.');
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/withdraw/crypto', name: 'app_crypto_withdraw', methods: ['POST'])]
    public function cryptoWithdraw(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // Rate limiting
        $limiter = $this->rateLimiter->create($request->getClientIp());
        if (false === $limiter->consume(1)->isAccepted()) {
            $this->addFlash('danger', 'Trop de tentatives. Veuillez réessayer plus tard.');
            return $this->redirectToRoute('app_profile');
        }

        // CSRF protection
        if (!$this->isCsrfTokenValid('crypto-withdraw', $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        $amountUSD = (float)$request->request->get('amount');
        $cryptoType = strtoupper($request->request->get('currency'));
        $address = trim($request->request->get('recipient'));

        // Input validation
        $constraints = new Assert\Collection([
            'amount' => [
                new Assert\NotBlank(),
                new Assert\Type(['type' => 'numeric']),
                new Assert\Range(['min' => 1, 'max' => 5000])
            ],
            'currency' => [
                new Assert\NotBlank(),
                new Assert\Choice(['choices' => ['BTC', 'ETH', 'TRX', 'USDT', 'USDT.TRC20', 'BNB', 'XRP']])
            ],
            'recipient' => [
                new Assert\NotBlank(),
                new Assert\Length(['min' => 26, 'max' => 64])
            ]
        ]);

        $violations = $this->validator->validate([
            'amount' => $amountUSD,
            'currency' => $cryptoType,
            'recipient' => $address
        ], $constraints);

        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $this->addFlash('danger', $violation->getMessage());
            }
            return $this->redirectToRoute('app_profile');
        }

        try {
            $this->entityManager->beginTransaction();

            // Lock user row for update
            $user = $this->entityManager->find(User::class, $user->getId(), \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE);

            $fees = $this->calculateWithdrawalFees($amountUSD, $cryptoType);
            $totalAmount = $amountUSD + $fees;

            if ($totalAmount > $user->getBalance()) {
                throw new \Exception('Insufficient balance');
            }

            $cryptoAmount = $this->convertUsdToCrypto($amountUSD, $cryptoType);
            $networkFee = $this->getNetworkFee($cryptoType);

            $transaction = (new Transactions())
                ->setUser($user)
                ->setAmount($amountUSD)
                ->setFees($fees)
                ->setType('withdrawal')
                ->setMethod('crypto_' . $cryptoType)
                ->setStatus('pending')
                ->setCreatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($transaction);
            $user->setBalance($user->getBalance() - $totalAmount);
            $this->entityManager->flush();
            $this->entityManager->commit();

            return $this->render('payment/withdraw_confirm.html.twig', [
                'transactionId' => $transaction->getId(),
                'cryptoType' => $cryptoType,
                'cryptoAmount' => $cryptoAmount,
                'recipientAddress' => $address,
                'networkFee' => $networkFee,
                'totalToSend' => $cryptoAmount - $networkFee,
                'fees' => $fees
            ]);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('Withdrawal failed', [
                'error' => $e->getMessage(),
                'user' => $user->getId(),
                'amount' => $amountUSD,
                'crypto' => $cryptoType
            ]);
            $this->addFlash('danger', 'Erreur lors du traitement du retrait');
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/paypal/redirect', name: 'app_paypal_redirect')]
    public function paypalRedirect(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // Rate limiting
        $limiter = $this->rateLimiter->create($request->getClientIp());
        if (false === $limiter->consume(1)->isAccepted()) {
            $this->addFlash('danger', 'Trop de tentatives. Veuillez réessayer plus tard.');
            return $this->redirectToRoute('app_profile');
        }

        $amount = (float)$request->query->get('amount');

        // Input validation
        $constraints = new Assert\Collection([
            'amount' => [
                new Assert\NotBlank(),
                new Assert\Type(['type' => 'numeric']),
                new Assert\Range(['min' => 1, 'max' => 10000])
            ]
        ]);

        $violations = $this->validator->validate([
            'amount' => $amount
        ], $constraints);

        if (count($violations) > 0) {
            foreach ($violations as $violation) {
                $this->addFlash('danger', $violation->getMessage());
            }
            return $this->redirectToRoute('app_profile');
        }

        try {
            $client = $this->getPayPalClient();
            $paypalRequest = new OrdersCreateRequest();
            $paypalRequest->prefer('return=representation');
            $paypalRequest->body = $this->createPayPalOrder($amount);

            $response = $client->execute($paypalRequest);

            foreach ($response->result->links as $link) {
                if ($link->rel === 'approve') {
                    $request->getSession()->set('paypal_order_id', $response->result->id);
                    $request->getSession()->set('paypal_amount', $amount);
                    return $this->redirect($link->href);
                }
            }

            throw new \Exception('URL PayPal introuvable');
        } catch (\Exception $e) {
            $this->logger->error('PayPal redirect failed', [
                'error' => $e->getMessage(),
                'amount' => $amount
            ]);
            $this->addFlash('danger', 'Erreur PayPal: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/paypal/return', name: 'paypal_return')]
    public function paypalReturn(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $orderId = $request->query->get('token') ?? $request->getSession()->get('paypal_order_id');
        $amount = $request->getSession()->get('paypal_amount');

        if (!$orderId || !$amount) {
            $this->addFlash('danger', 'Commande PayPal introuvable');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        try {
            $client = $this->getPayPalClient();
            $response = $client->execute(new OrdersCaptureRequest($orderId));

            if ($response->result->status === 'COMPLETED') {
                $this->createPayPalTransaction($user, $amount, $orderId);
                $request->getSession()->remove('paypal_order_id');
                $request->getSession()->remove('paypal_amount');
                $this->addFlash('success', 'Paiement PayPal réussi !');
            } else {
                throw new \Exception('Statut PayPal non complet: ' . $response->result->status);
            }
        } catch (\Exception $e) {
            $this->logger->error('PayPal return processing failed', [
                'error' => $e->getMessage(),
                'orderId' => $orderId,
                'user' => $user->getId()
            ]);
            $this->addFlash('danger', 'Erreur lors du traitement PayPal: ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_profile');
    }

    #[Route('/paypal/cancel', name: 'paypal_cancel')]
    public function paypalCancel(Request $request): Response
    {
        $request->getSession()->remove('paypal_order_id');
        $request->getSession()->remove('paypal_amount');
        $this->addFlash('warning', 'Paiement PayPal annulé');
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/check-deposits', name: 'app_check_deposits')]
    public function checkDeposits(): Response
    {
        // Security: only accessible via CLI or admin
        if (!$this->isGranted('ROLE_ADMIN') && php_sapi_name() !== 'cli') {
            throw $this->createAccessDeniedException();
        }

        $transactions = $this->entityManager->getRepository(Transactions::class)
            ->findPendingDeposits();

        $processed = 0;
        foreach ($transactions as $transaction) {
            try {
                $this->verifyAndProcessDeposit($transaction);
                $processed++;
            } catch (\Exception $e) {
                $this->logger->error('Deposit verification failed', [
                    'transaction' => $transaction->getId(),
                    'error' => $e->getMessage()
                ]);
            }
        }

        return new Response(sprintf('Processed %d/%d deposits', $processed, count($transactions)));
    }

    private function verifyAndProcessDeposit(Transactions $transaction): void
    {
        $this->entityManager->beginTransaction();

        try {
            // Lock transaction row for update
            $transaction = $this->entityManager->find(
                Transactions::class,
                $transaction->getId(),
                \Doctrine\DBAL\LockMode::PESSIMISTIC_WRITE
            );

            if ($transaction->getStatus() !== 'pending') {
                return;
            }

            $verified = $this->verifyBlockchainTransaction($transaction);

            if ($verified) {
                $this->transferToMainWallet($transaction);

                $user = $transaction->getUser();
                $user->setBalance($user->getBalance() + $transaction->getAmount());

                $transaction->setStatus('completed');
                $this->entityManager->flush();
                $this->entityManager->commit();

                $this->logger->info('Deposit processed successfully', [
                    'transaction' => $transaction->getId(),
                    'user' => $user->getId(),
                    'amount' => $transaction->getAmount()
                ]);
            }
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    private function verifyBlockchainTransaction(Transactions $transaction): bool
    {
        $method = $transaction->getMethod();
        $crypto = str_replace('crypto_', '', $method);
        $txId = $transaction->getExternalId();

        try {
            if ($crypto === 'TRX' || $crypto === 'USDT.TRC20') {
                $transactionInfo = $this->tron->getTransaction($txId);

                if ($transactionInfo['ret'][0]['contractRet'] === 'SUCCESS') {
                    $transaction->setExternalAddress($transactionInfo['raw_data']['contract'][0]['parameter']['value']['owner_address']);
                    return true;
                }
            }

            return false;
        } catch (TronErrorException $e) {
            $this->logger->error('Blockchain verification failed', [
                'transaction' => $transaction->getId(),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function transferToMainWallet(Transactions $transaction): void
    {
        $crypto = str_replace('crypto_', '', $transaction->getMethod());
        $amount = $transaction->getAmount();
        $fromAddress = $transaction->getExternalAddress();
        $mainAddress = $_ENV['TRON_MAIN_WALLET'];

        try {
            if ($crypto === 'TRX') {
                $this->tron->sendTRX($fromAddress, $mainAddress, $amount);
            } elseif ($crypto === 'USDT.TRC20') {
                $this->tron->sendTRC20Token($fromAddress, $mainAddress, $amount);
            }

            $this->logger->info('Funds transferred to main wallet', [
                'transaction' => $transaction->getId(),
                'amount' => $amount,
                'crypto' => $crypto
            ]);
        } catch (TronErrorException $e) {
            $this->logger->error('Funds transfer failed', [
                'error' => $e->getMessage(),
                'transaction' => $transaction->getId()
            ]);
            throw new \Exception('Transfer failed');
        }
    }

    private function generateSecureDepositAddress(string $crypto, Transactions $transaction): string
    {
        try {
            if ($crypto === 'TRX') {
                $address = $this->tron->generateAddress();
                $this->tron->assignDepositAddress($address, $transaction->getId());
                return $address->address;
            } elseif ($crypto === 'USDT.TRC20') {
                $address = $this->tron->generateAddress();
                $this->tron->assignDepositAddress($address, $transaction->getId());
                return $address->address;
            }

            throw new \Exception('Unsupported crypto for direct generation');
        } catch (TronErrorException $e) {
            $this->logger->error('Address generation failed', [
                'error' => $e->getMessage(),
                'transaction' => $transaction->getId()
            ]);
            throw new \Exception('Failed to generate address');
        }
    }

    private function validateCryptoAddress(string $crypto, string $address): bool
    {
        try {
            if ($crypto === 'TRX' || $crypto === 'USDT.TRC20') {
                return $this->tron->validateAddress($address);
            }

            // Fallback to API validation for other cryptos
            $response = $this->tronGridClient->get("wallet/validateaddress?address=$address");
            $data = json_decode($response->getBody(), true);
            return $data['valid'] ?? false;
        } catch (\Exception $e) {
            $this->logger->error('Address validation failed', [
                'error' => $e->getMessage(),
                'crypto' => $crypto,
                'address' => substr($address, 0, 10) . '...'
            ]);
            return false;
        }
    }

    private function getCurrentRate(string $crypto): float
    {
        $cacheKey = 'crypto_rate_' . $crypto;

        return $this->cache->get($cacheKey, function (ItemInterface $item) use ($crypto) {
            $item->expiresAfter(300); // 5 min cache

            try {
                $response = $this->coinGeckoClient->get("simple/price?ids=$crypto&vs_currencies=usd");
                $data = json_decode($response->getBody(), true);

                if (!isset($data[$crypto]['usd'])) {
                    throw new \Exception('Exchange rate not available');
                }

                return (float)$data[$crypto]['usd'];
            } catch (\Exception $e) {
                $this->logger->error('Rate fetch failed', [
                    'crypto' => $crypto,
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }
        });
    }

    private function convertUsdToCrypto(float $usdAmount, string $crypto): float
    {
        $rate = $this->getCurrentRate($crypto);
        return $usdAmount / $rate;
    }

    private function getNetworkFee(string $crypto): float
    {
        try {
            $rate = $this->getCurrentRate($crypto);

            return match ($crypto) {
                'BTC' => 0.0003 * $rate,
                'ETH' => 0.0005 * $rate,
                'TRX' => 0.1 * $rate,
                'USDT.TRC20' => 1.0,
                default => 1.0
            };
        } catch (\Exception $e) {
            $this->logger->error('Network fee calculation failed', [
                'error' => $e->getMessage(),
                'crypto' => $crypto
            ]);
            return 1.0;
        }
    }

    private function calculateWithdrawalFees(float $amount, string $crypto): float
    {
        $baseFee = max($amount * 0.01, 1.0);

        if (in_array($crypto, ['BTC', 'ETH'])) {
            $baseFee += 0.5;
        }

        return $baseFee;
    }

    private function isCryptoSupported(string $crypto): bool
    {
        $supported = [
            'BTC',
            'ETH',
            'TRX',
            'USDT',
            'USDT.TRC20',
            'USDT.ERC20',
            'BNB',
            'XRP'
        ];

        return in_array($crypto, $supported);
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

    private function createPayPalTransaction(User $user, float $amount, string $orderId): void
    {
        $this->entityManager->beginTransaction();

        try {
            $transaction = (new Transactions())
                ->setUser($user)
                ->setAmount($amount)
                ->setType('deposit')
                ->setMethod('paypal')
                ->setStatus('completed')
                ->setExternalId($orderId)
                ->setCreatedAt(new \DateTimeImmutable());

            $user->setBalance($user->getBalance() + $amount);

            $this->entityManager->persist($transaction);
            $this->entityManager->persist($user);
            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->logger->info('PayPal transaction completed', [
                'transaction' => $transaction->getId(),
                'user' => $user->getId(),
                'amount' => $amount
            ]);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logger->error('PayPal transaction failed', [
                'error' => $e->getMessage(),
                'orderId' => $orderId,
                'user' => $user->getId()
            ]);
            throw $e;
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
}