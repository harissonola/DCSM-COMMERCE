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
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class PaymentController extends AbstractController
{
    private const MIN_WITHDRAWAL_AMOUNT = 2.0;
    private const MIN_DEPOSIT_AMOUNT = 1.0;
    private const MAX_DEPOSIT_AMOUNT = 10000.0;
    private const MAX_WITHDRAWAL_AMOUNT = 5000.0;
    private const DEPOSIT_EXPIRATION_HOURS = 2;

    private const CRYPTO_NETWORKS = [
        'USDT.TRC20' => [
            'name' => 'USDT (TRC20)',
            'validation_regex' => '/^T[1-9A-HJ-NP-Za-km-z]{33}$/',
            'explorer' => 'https://apilist.tronscan.org/api/transaction-info?hash='
        ],
        'USDT.ERC20' => [
            'name' => 'USDT (ERC20)',
            'validation_regex' => '/^0x[a-fA-F0-9]{40}$/',
            'explorer' => 'https://api.etherscan.io/api?module=account&action=tokentx&contractaddress=0xdac17f958d2ee523a2206206994597c13d831ec7&txhash='
        ]
    ];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private RequestStack $requestStack,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private ValidatorInterface $validator,
        private RateLimiterFactory $paymentLimiter
    ) {}

    #[Route('/withdraw', name: 'app_withdraw', methods: ['POST'])]
    public function withdraw(Request $request): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // Validation CSRF
        $token = new CsrfToken('withdraw', $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->redirectWithFlash('danger', 'Token CSRF invalide');
        }

        $user = $this->getUser();
        $amount = (float)$request->request->get('amount');
        $currency = strtoupper(trim($request->request->get('currency')));
        $address = trim($request->request->get('address'));

        // Validation
        $errors = $this->validateWithdrawal($user, $amount, $currency, $address);
        if (!empty($errors)) {
            return $this->redirectWithFlash('danger', $errors[0]);
        }

        // Calcul des frais
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
            ->setExternalId($address);

        $this->entityManager->beginTransaction();
        try {
            $user->setBalance($user->getBalance() - $totalAmount);
            $this->entityManager->persist($transaction);
            $this->entityManager->flush();

            // Intégration avec le service de retrait
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

        // Validation CSRF
        $token = new CsrfToken('deposit', $request->request->get('_token'));
        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return $this->redirectWithFlash('danger', 'Token CSRF invalide');
        }

        // Rate limiting
        $limiter = $this->paymentLimiter->create($this->getUser()->getUserIdentifier());
        if (!$limiter->consume()->isAccepted()) {
            return $this->redirectWithFlash('danger', 'Trop de tentatives. Veuillez patienter.');
        }

        $amount = (float)$request->request->get('amount');
        $method = $request->request->get('method');

        // Validation
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
            return $this->redirectToRoute('app_crypto_deposit', ['id' => $transaction->getId()]);
        }

        return $this->redirectToRoute('app_paypal_redirect', ['id' => $transaction->getId()]);
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

    #[Route('/deposit/crypto/{id}', name: 'app_crypto_deposit')]
    public function cryptoDeposit(int $id): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $transaction = $this->entityManager->getRepository(Transactions::class)->find($id);
        if (!$transaction || $transaction->getUser() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Transaction invalide');
        }

        // Génération d'une adresse de dépôt unique
        $depositAddress = $this->generateDepositAddress('USDT.TRC20');
        $expiresAt = (new \DateTime())->modify('+' . self::DEPOSIT_EXPIRATION_HOURS . ' hours');

        $transaction
            ->setExternalId($depositAddress)
            ->setExpiresAt($expiresAt);

        $this->entityManager->flush();

        return $this->render('payment/crypto_deposit.html.twig', [
            'transaction' => $transaction,
            'amount' => $transaction->getAmount(),
            'depositAddress' => $depositAddress,
            'expiresAt' => $expiresAt,
            'network' => 'USDT (TRC20)',
            'qrCodeUrl' => $this->generateUrl('app_qr_code', [
                'data' => $depositAddress,
                'size' => 300
            ], UrlGeneratorInterface::ABSOLUTE_URL),
            'csrf_token' => $this->csrfTokenManager->getToken('crypto_deposit')->getValue()
        ]);
    }

    #[Route('/deposit/crypto/check/{id}', name: 'app_check_crypto_deposit', methods: ['POST'])]
    public function checkCryptoDeposit(int $id, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // Validation CSRF
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

        // Vérification du dépôt
        $isConfirmed = $this->verifyBlockchainDeposit($transaction->getExternalId(), $transaction->getAmount());

        if ($isConfirmed) {
            $this->completeDeposit($transaction);
            return $this->json(['status' => 'completed']);
        }

        return $this->json(['status' => 'pending']);
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

        if (!array_key_exists($currency, self::CRYPTO_NETWORKS)) {
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

    private function calculateWithdrawalFees(float $amount): float
    {
        if ($amount <= 20) return max($amount * 0.05, 1.0);
        if ($amount <= 100) return $amount * 0.03;
        if ($amount <= 500) return $amount * 0.02;
        return $amount * 0.01;
    }

    private function validateCryptoAddress(string $currency, string $address): bool
    {
        return preg_match(self::CRYPTO_NETWORKS[$currency]['validation_regex'], $address) === 1;
    }

    private function generateDepositAddress(string $currency): string
    {
        return 'T' . bin2hex(random_bytes(16));
    }

    private function verifyBlockchainDeposit(string $address, float $amount): bool
    {
        // Implémentation réelle avec l'API blockchain
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

        $environment = $_ENV['APP_ENV'] === 'prod'
            ? new ProductionEnvironment($clientId, $clientSecret)
            : new SandboxEnvironment($clientId, $clientSecret);

        return new PayPalHttpClient($environment);
    }

    private function getSession()
    {
        return $this->requestStack->getSession();
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
}