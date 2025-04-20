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
    public const PAYMENT_TOLERANCE = 0.05; // 5% tolerance for payment amounts

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

        // Validation initiale
        if ($user->getBalance() < $amount) {
            dd("Solde insuffisant");
            $this->addFlash('danger', 'Solde insuffisant');
            return $this->redirectToRoute('app_profile');
        }

        $errors = $this->validateWithdrawal($user, $amount, $currency, $address);
        if (!empty($errors)) {
            $this->addFlash('danger', $errors[0]);
            return $this->redirectToRoute('app_profile');
        }

        $fees = $this->calculateWithdrawalFees($amount);
        $totalAmount = $amount + $fees;

        // Création de la transaction
        $transaction = (new Transactions())
            ->setUser($user)
            ->setType('withdrawal')
            ->setAmount($amount)
            ->setFees($fees)
            ->setMethod('crypto_' . $currency)
            ->setStatus('pending')
            ->setCreatedAt(new DateTimeImmutable())
            ->setExternalId('WITHDRAW_' . $user->getId() . '_' . time());

        $this->entityManager->beginTransaction();
        try {
            // Mise à jour du solde utilisateur
            $user->setBalance($user->getBalance() - $totalAmount);
            $this->entityManager->persist($transaction);
            $this->entityManager->flush();

            // Appel API NowPayments avec gestion améliorée
            $payoutResponse = $this->httpClient->post('https://api.nowpayments.io/v1/payout', [
                'headers' => [
                    'x-api-key' => $_ENV['NOWPAYMENTS_API_KEY'],
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $_ENV['NOWPAYMENTS_API_KEY']
                ],
                'json' => [
                    'withdrawal_amount' => $amount,
                    'withdrawal_currency' => $currency,
                    'address' => $address,
                    'ipn_callback_url' => $this->generateUrl('nowpayments_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL),
                    'unique_external_id' => $transaction->getExternalId()
                ],
                'timeout' => 15
            ]);

            $responseData = json_decode($payoutResponse->getBody(), true);

            if (!isset($responseData['payout_id'])) {
                throw new \RuntimeException('Réponse API invalide: ' . json_encode($responseData));
            }

            // Mise à jour de la transaction avec plus de métadonnées
            $transaction
                ->setExternalId($responseData['payout_id'])
                ->setStatus('processing')
                ->setMetadata([
                    'np_response' => $responseData,
                    'wallet_address' => $address,
                    'currency' => $currency,
                    'fees_breakdown' => [
                        'amount' => $amount,
                        'fees' => $fees,
                        'total_deducted' => $totalAmount
                    ],
                    'api_timestamp' => $responseData['created_at'] ?? null
                ]);

            $this->entityManager->flush();
            $this->entityManager->commit();

            // Notification
            $this->sendWithdrawalConfirmationEmail($user, $transaction);

            $this->addFlash('success', sprintf(
                'Demande de retrait de %.2f %s enregistrée. Frais: %.2f %s. Le traitement peut prendre jusqu\'à 24h.',
                $amount,
                strtoupper($currency),
                $fees,
                strtoupper($currency)
            ));

            // Log de succès
            $this->logInfo('Withdrawal initiated', [
                'user_id' => $user->getId(),
                'transaction_id' => $transaction->getId(),
                'amount' => $amount,
                'currency' => $currency,
                'payout_id' => $responseData['payout_id']
            ]);
        } catch (\Exception $e) {
            $this->entityManager->rollback();

            // Log détaillé de l'erreur
            $this->logError('Withdrawal failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => [
                    'user_id' => $user->getId(),
                    'amount' => $amount,
                    'currency' => $currency,
                    'address' => substr($address, 0, 10) . '...' // Masquage partiel pour la sécurité
                ]
            ]);

            $this->addFlash('danger', 'Erreur lors du traitement du retrait. Notre équipe a été notifiée.');
            return $this->redirectToRoute('app_profile');
        }

        return $this->redirectToRoute('app_profile');
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

        file_put_contents($ipnLogPath, "\n\n[{$now->format('Y-m-d H:i:s')}] Nouvelle requête IPN\n", FILE_APPEND);
        file_put_contents($ipnLogPath, "Headers: " . json_encode($request->headers->all()) . "\n", FILE_APPEND);
        file_put_contents($ipnLogPath, "Raw payload: " . $request->getContent() . "\n", FILE_APPEND);

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
            file_put_contents($ipnLogPath, "ERREUR: Signature HMAC invalide\n", FILE_APPEND);
            return new JsonResponse(['error' => 'Invalid HMAC signature'], 403);
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            file_put_contents($ipnLogPath, "Payload décodé: " . print_r($data, true) . "\n", FILE_APPEND);

            // Détection du type de notification
            if (isset($data['payout_id'])) {
                return $this->handleWithdrawalIPN($data);
            } elseif (isset($data['payment_id'])) {
                return $this->handleDepositIPN($data);
            }

            throw new \RuntimeException("Type de notification IPN non reconnu");
        } catch (\Exception $e) {
            file_put_contents($ipnLogPath, "ERREUR: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
            return new JsonResponse(['error' => $e->getMessage()], 400);
        }
    }

    private function handleWithdrawalIPN(array $ipnData): JsonResponse
    {
        $requiredFields = ['payout_id', 'withdrawal_status', 'withdrawal_id', 'amount', 'currency'];
        foreach ($requiredFields as $field) {
            if (!isset($ipnData[$field])) {
                throw new \RuntimeException("Champ manquant dans l'IPN de retrait: $field");
            }
        }

        $transaction = $this->entityManager->getRepository(Transactions::class)
            ->findOneBy(['external_id' => $ipnData['payout_id'], ['type' => 'withdrawal']]);

        if (!$transaction) {
            throw new \RuntimeException("Transaction de retrait introuvable pour payout_id: " . $ipnData['payout_id']);
        }

        $this->entityManager->beginTransaction();
        try {
            $user = $transaction->getUser();
            $metadata = $transaction->getMetadata() ?? [];

            switch ($ipnData['withdrawal_status']) {
                case 'finished':
                    $transaction->setStatus('completed')
                        ->setVerifiedAt(new \DateTime())
                        ->setMetadata(array_merge($metadata, [
                            'ipn_data' => $ipnData,
                            'tx_hash' => $ipnData['txid'] ?? null
                        ]));

                    $this->sendWithdrawalCompletedEmail($user, $transaction, $ipnData);
                    break;

                case 'failed':
                    // Rembourser l'utilisateur en cas d'échec
                    $user->setBalance($user->getBalance() + $transaction->getAmount() + $transaction->getFees());

                    $transaction->setStatus('failed')
                        ->setMetadata(array_merge($metadata, [
                            'ipn_data' => $ipnData,
                            'failure_reason' => $ipnData['error_message'] ?? 'unknown'
                        ]));

                    $this->sendWithdrawalFailedEmail($user, $transaction, $ipnData);
                    break;

                case 'pending':
                    $transaction->setStatus('processing')
                        ->setMetadata(array_merge($metadata, ['ipn_data' => $ipnData]));
                    break;

                default:
                    throw new \RuntimeException("Statut de retrait non géré: " . $ipnData['withdrawal_status']);
            }

            $this->entityManager->flush();
            $this->entityManager->commit();

            return new JsonResponse(['status' => 'success'], 200);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
        }
    }

    private function handleDepositIPN(array $ipnData): JsonResponse
    {
        $requiredFields = ['payment_id', 'payment_status', 'actually_paid', 'pay_currency', 'order_id'];
        foreach ($requiredFields as $field) {
            if (!isset($ipnData[$field])) {
                throw new \RuntimeException("Champ manquant: $field");
            }
        }

        if (!preg_match('/^DEPO_(\d+)_\d+$/', $ipnData['order_id'], $matches)) {
            throw new \RuntimeException("Format order_id invalide");
        }
        $transactionId = $matches[1];

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($transactionId);
        if (!$transaction) {
            throw new \RuntimeException("Transaction introuvable pour ID: $transactionId");
        }

        if ($transaction->getType() !== 'deposit') {
            throw new \RuntimeException("Type de transaction invalide");
        }

        $expectedCurrency = str_replace('crypto_', '', $transaction->getMethod());
        if (strtolower($ipnData['pay_currency']) !== strtolower($expectedCurrency)) {
            throw new \RuntimeException(sprintf(
                "Devise mismatch: attendu %s, reçu %s",
                $expectedCurrency,
                $ipnData['pay_currency']
            ));
        }

        switch ($ipnData['payment_status']) {
            case 'finished':
                $this->handleSuccessfulPayment($transaction, $ipnData);
                break;

            case 'partially_paid':
                $this->handlePartialPayment($transaction, $ipnData);
                break;

            case 'failed':
                $this->handleFailedPayment($transaction, $ipnData);
                break;

            case 'expired':
                $this->handleExpiredPayment($transaction);
                break;

            default:
                throw new \RuntimeException("Statut de paiement non géré: {$ipnData['payment_status']}");
        }

        return new JsonResponse(['status' => 'success'], 200);
    }

    private function sendWithdrawalConfirmationEmail(User $user, Transactions $transaction): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@bictrary.com', 'Bictrary'))
            ->to($user->getEmail())
            ->subject('Confirmation de demande de retrait')
            ->htmlTemplate('emails/withdrawal_requested.html.twig')
            ->context([
                'amount' => $transaction->getAmount(),
                'currency' => str_replace('crypto_', '', $transaction->getMethod()),
                'fees' => $transaction->getFees(),
                'address' => $transaction->getMetadata()['wallet_address'],
                'date' => new \DateTime()
            ]);

        $this->mailer->send($email);
    }

    private function sendWithdrawalCompletedEmail(User $user, Transactions $transaction, array $ipnData): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@bictrary.com', 'Bictrary'))
            ->to($user->getEmail())
            ->subject('Retrait complété')
            ->htmlTemplate('emails/withdrawal_completed.html.twig')
            ->context([
                'amount' => $transaction->getAmount(),
                'currency' => str_replace('crypto_', '', $transaction->getMethod()),
                'tx_hash' => $ipnData['txid'] ?? null,
                'address' => $transaction->getMetadata()['wallet_address'],
                'date' => new \DateTime()
            ]);

        $this->mailer->send($email);
    }

    private function sendWithdrawalFailedEmail(User $user, Transactions $transaction, array $ipnData): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('no-reply@bictrary.com', 'Bictrary'))
            ->to($user->getEmail())
            ->subject('Échec du retrait')
            ->htmlTemplate('emails/withdrawal_failed.html.twig')
            ->context([
                'amount' => $transaction->getAmount(),
                'currency' => str_replace('crypto_', '', $transaction->getMethod()),
                'reason' => $ipnData['error_message'] ?? 'Raison inconnue',
                'address' => $transaction->getMetadata()['wallet_address'],
                'date' => new \DateTime()
            ]);

        $this->mailer->send($email);
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

            $this->logInfo('Paiement partiel reçu', [
                'transaction_id' => $transaction->getId(),
                'received_amount' => $receivedAmount,
                'remaining_amount' => $transaction->getAmount() - $receivedAmount
            ]);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
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

            $this->logInfo('Paiement complété avec succès', [
                'transaction_id' => $transaction->getId(),
                'user_id' => $user->getId(),
                'amount_credited' => $expectedAmount
            ]);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            $this->logError('Échec du traitement du paiement', [
                'error' => $e->getMessage(),
                'transaction_id' => $transaction->getId()
            ]);
            throw $e;
        }
    }

    private function sendConfirmationEmail(User $user, Transactions $transaction, array $ipnData): void
    {
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
    }

    private function handleExpiredPayment(Transactions $transaction): void
    {
        if ($transaction->getStatus() !== 'expired') {
            $transaction->setStatus('expired');
            $this->entityManager->flush();
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
                ->from(new Address('no-reply@bictrary.com', 'Bictrary'))
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
