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
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Email;

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
        // Frais fixes de 15% pour tous les retraits
        return $amount * 0.15;
    }

    private function handleInvitationReward(User $invitedUser, float $depositAmount): void
    {
        $inviter = $invitedUser->getInvitedBy();
        if (!$inviter) {
            return;
        }

        $rewardAmount = $depositAmount * 0.10; // 10% du dépôt

        // Créer une transaction de récompense
        $rewardTransaction = new Transactions();
        $rewardTransaction->setUser($inviter)
            ->setType('invitation_reward')
            ->setAmount($rewardAmount)
            ->setFees(0)
            ->setMethod('system')
            ->setStatus('completed')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setMetadata([
                'invited_user_id' => $invitedUser->getId(),
                'deposit_amount' => $depositAmount
            ]);

        // Mettre à jour le solde de l'inviteur
        $inviter->setBalance($inviter->getBalance() + $rewardAmount);

        $this->entityManager->persist($rewardTransaction);
        $this->entityManager->flush();

        // Envoyer un email de notification à l'inviteur
        $this->sendInvitationRewardEmail($inviter, $invitedUser, $rewardAmount);
    }

    #[Route('/withdraw', name: 'app_withdraw', methods: ['POST'])]
    public function withdraw(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // Validation CSRF
        $token = new CsrfToken('withdraw', $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('danger', 'Token CSRF invalide');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        $amount = (float)$request->request->get('amount');
        $currency = strtolower(trim($request->request->get('currency')));
        $address = trim($request->request->get('address'));

        // Validations
        if ($user->getBalance() < $amount) {

            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('danger', 'Solde insuffisant');
            //$session->getFlashBag()->peekAll();
            return $this->redirectToRoute('app_profile', [], Response::HTTP_SEE_OTHER);
        }

        $errors = $this->validateWithdrawal($user, $amount, $currency, $address);
        if (!empty($errors)) {
            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('danger', $errors[0]);
            return $this->redirectToRoute('app_profile');
        }

        $fees = $this->calculateWithdrawalFees($amount);
        $totalAmount = $amount + $fees;

        try {
            $jwtToken = $this->getFreshJwtToken();

            $payload = [
                'withdrawals' => [
                    [
                        'address' => $address,
                        'currency' => $currency,
                        'amount' => $amount
                    ]
                ],
                'ipn_callback_url' => $this->generateUrl('nowpayments_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ];

            $response = $this->httpClient->post('https://api.nowpayments.io/v1/payout', [
                'headers' => [
                    'x-api-key' => $_ENV['NOWPAYMENTS_API_KEY'],
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $jwtToken
                ],
                'json' => $payload,
                'timeout' => 20
            ]);

            $responseData = json_decode($response->getBody(), true);

            if (!isset($responseData['id'])) {
                throw new \RuntimeException('Réponse API incomplète');
            }

            // Création de la transaction en statut "pending" sans soustraire le solde
            $transaction = new Transactions();
            $transaction->setUser($user)
                ->setType('withdrawal')
                ->setAmount($amount)
                ->setFees($fees)
                ->setMethod('crypto_' . $currency)
                ->setStatus('pending')
                ->setCreatedAt(new \DateTimeImmutable())
                ->setExternalId($responseData['id'])
                ->setMetadata([
                    'wallet_address' => $address,
                    'api_response' => $responseData,
                    'total_amount' => $totalAmount
                ]);

            $this->entityManager->persist($transaction);
            $this->entityManager->flush();

            // Envoi de l'email de confirmation
            $this->sendWithdrawalRequestedEmail($user, $transaction);

            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('success', 'Demande de retrait enregistrée. Le solde sera débité après confirmation.');
        } catch (\Exception $e) {
            $this->logError('Withdrawal failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId()
            ]);
            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('danger', 'Erreur lors de la demande de retrait');
        }

        return $this->redirectToRoute('app_profile');
    }

    private function sendWithdrawalRequestedEmail(User $user, Transactions $transaction): void
    {
        try {
            $email = (new TemplatedEmail())
                ->from(new Address('no-reply@bictrary.com', 'Bictrary'))
                ->to($user->getEmail())
                ->subject('Confirmation de votre demande de retrait')
                ->htmlTemplate('emails/withdrawal_requested.html.twig')
                ->context([
                    'amount' => $transaction->getAmount(),
                    'currency' => str_replace('crypto_', '', $transaction->getMethod()),
                    'fees' => $transaction->getFees(),
                    'address' => $transaction->getMetadata()['wallet_address'],
                    'date' => new \DateTime(),
                    'total_amount' => $transaction->getAmount() + $transaction->getFees()
                ]);

            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logError('Failed to send withdrawal requested email', [
                'error' => $e->getMessage(),
                'user_id' => $user->getId(),
                'transaction_id' => $transaction->getId()
            ]);
        }
    }

    private function handleWithdrawalIPN(array $ipnData): JsonResponse
    {
        // Vérification des champs obligatoires
        $requiredFields = ['batch_withdrawal_id', 'status', 'amount', 'currency'];
        foreach ($requiredFields as $field) {
            if (!isset($ipnData[$field])) {
                throw new \RuntimeException("Champ manquant: $field");
            }
        }

        // Trouver la transaction correspondante
        $transaction = $this->entityManager->getRepository(Transactions::class)
            ->findOneBy(['ExternalId' => $ipnData['batch_withdrawal_id'], 'type' => 'withdrawal']);

        if (!$transaction) {
            throw new \RuntimeException("Transaction introuvable pour batch_withdrawal_id: {$ipnData['batch_withdrawal_id']}");
        }

        $user = $transaction->getUser();
        $totalAmount = $transaction->getAmount() + $transaction->getFees();

        $this->entityManager->beginTransaction();
        try {
            switch (strtolower($ipnData['status'])) {
                case 'finished':
                    $user->setBalance($user->getBalance() - $totalAmount);
                    $transaction->setStatus('completed');
                    $this->sendWithdrawalCompletedEmail($user, $transaction, $ipnData);
                    break;

                case 'rejected':
                    $transaction->setStatus('failed');
                    $this->sendWithdrawalFailedEmail($user, $transaction, $ipnData);
                    break;

                case 'pending':
                    $transaction->setStatus('pending');
                    break;

                case 'CREATING':
                    $transaction->setStatus('pending');
                    break;

                case 'creating':
                    $transaction->setStatus('pending');
                    break;

                default:
                    throw new \RuntimeException("Statut inconnu: {$ipnData['status']}");
            }

            $metadata = $transaction->getMetadata() ?? [];
            $metadata['ipn_data'] = $ipnData;
            $metadata['updated_at'] = (new \DateTime())->format('c');
            $transaction->setMetadata($metadata);

            $this->entityManager->flush();
            $this->entityManager->commit();

            return new JsonResponse(['status' => 'success']);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
            throw $e;
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
            if (isset($data['payment_id'])) {
                return $this->handleDepositIPN($data);
            } elseif (isset($data['batch_withdrawal_id'])) {
                return $this->handleWithdrawalIPN($data);
            }

            throw new \RuntimeException("Type de notification IPN non reconnu");
        } catch (\Exception $e) {
            file_put_contents($ipnLogPath, "ERREUR: " . $e->getMessage() . "\nStack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
            return new JsonResponse(['error' => $e->getMessage()], 400);
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

            case 'failed':
                $this->handleWaitingPayment($transaction, $ipnData);
                break;

            case 'expired':
                $this->handleExpiredPayment($transaction);
                break;

            default:
                throw new \RuntimeException("Statut de paiement non géré: {$ipnData['payment_status']}");
        }

        return new JsonResponse(['status' => 'success'], 200);
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
                'tx_hash' => $ipnData['hash'] ?? null,
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
                'reason' => $ipnData['error'] ?? 'Raison inconnue',
                'address' => $transaction->getMetadata()['wallet_address'],
                'date' => new \DateTime()
            ]);

        $this->mailer->send($email);
    }

    private function handleWaitingPayment(Transactions $transaction, array $ipnData): void
    {
        $this->entityManager->beginTransaction();
        try {
            $receivedAmount = (float)$ipnData['waiting'];

            $transaction
                ->setStatus('pending')
                ->setMetadata(array_merge(
                    $transaction->getMetadata() ?? [],
                    [
                        'ipn_data' => $ipnData,
                        'waiting' => $receivedAmount,
                        'remaining_amount' => $transaction->getAmount() - $receivedAmount
                    ]
                ));

            $this->entityManager->flush();
            $this->entityManager->commit();

            $this->logInfo('Paiement en cours (waiting)', [
                'transaction_id' => $transaction->getId(),
                'received_amount' => $receivedAmount,
                'remaining_amount' => $transaction->getAmount() - $receivedAmount
            ]);
        } catch (\Exception $e) {
            $this->entityManager->rollback();
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

            // Gérer la récompense d'invitation si c'est un dépôt
            if ($transaction->getType() === 'deposit') {
                $this->handleInvitationReward($user, $expectedAmount);
            }

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

    private function sendInvitationRewardEmail(User $inviter, User $invitedUser, float $rewardAmount): void
    {
        try {
            $email = (new TemplatedEmail())
                ->from(new Address('no-reply@bictrary.com', 'Bictrary'))
                ->to($inviter->getEmail())
                ->subject('Vous avez reçu une récompense d\'invitation')
                ->htmlTemplate('emails/invitation_reward.html.twig')
                ->context([
                    'invited_user' => $invitedUser,
                    'reward_amount' => $rewardAmount,
                    'date' => new \DateTime()
                ]);

            $this->mailer->send($email);
        } catch (\Exception $e) {
            $this->logError('Failed to send invitation reward email', [
                'error' => $e->getMessage(),
                'inviter_id' => $inviter->getId(),
                'invited_user_id' => $invitedUser->getId()
            ]);
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

    private function getFreshJwtToken(): string
    {
        $authResponse = $this->httpClient->post('https://api.nowpayments.io/v1/auth', [
            'headers' => [
                'x-api-key' => $_ENV['NOWPAYMENTS_API_KEY'],
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'email' => $_ENV['NOWPAYMENTS_API_EMAIL'],
                'password' => $_ENV['NOWPAYMENTS_API_PASSWORD']
            ],
            'timeout' => 10
        ]);

        $authData = json_decode($authResponse->getBody(), true);
        if (!isset($authData['token'])) {
            throw new \RuntimeException('Échec de l\'authentification: ' . json_encode($authData));
        }
        return $authData['token'];
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

    #[Route('/deposit', name: 'app_deposit', methods: ['POST'])]
    public function deposit(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $token = new CsrfToken('deposit', $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('danger', 'Token CSRF invalide');
            return $this->redirectToRoute('app_profile');
        }

        $amount = (float)$request->request->get('amount');
        $method = $request->request->get('method');

        $errors = $this->validateDeposit($amount, $method);
        if (!empty($errors)) {
            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('danger', $errors[0]);
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
            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('danger', 'Token CSRF invalide');
            return $this->redirectToRoute('app_profile');
        }

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if (!$transaction || $transaction->getUser() !== $this->getUser()) {
            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('danger', 'Transaction invalide');
            return $this->redirectToRoute('app_profile');
        }

        $cryptoType = strtolower($request->request->get('crypto_type'));
        $sourceAddress = trim($request->request->get('source_address'));

        if (!array_key_exists($cryptoType, self::SUPPORTED_CRYPTOS)) {
            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('danger', 'Type de crypto non supporté');
            return $this->redirectToRoute('app_profile');
        }

        if (empty($sourceAddress)) {
            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('danger', 'Veuillez fournir votre adresse source');
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
            // Avant la redirection dans votre contrôleur
            $session = $request->getSession();
            $session->getFlashBag()->add('danger', 'Erreur lors de la création du dépôt: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
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

    #[Route('/deposit/success', name: 'deposit_success')]
    public function depositSuccess(): Response
    {
        // Avant la redirection dans votre contrôleur
        $session = $request->getSession();
        $session->getFlashBag()->add('success', 'Dépôt effectué avec succès');
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/deposit/cancel', name: 'deposit_cancel')]
    public function depositCancel(): Response
    {
        // Avant la redirection dans votre contrôleur
        $session = $request->getSession();
        $session->getFlashBag()->add('warning', 'Dépôt annulé');
        return $this->redirectToRoute('app_profile');
    }
}
