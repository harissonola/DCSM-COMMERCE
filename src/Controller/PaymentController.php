<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Transactions;
use App\Service\CoinbaseService;
use App\Service\CoinbasePayoutService;

class PaymentController extends AbstractController
{
    private CoinbaseService $coinbase;
    private CoinbasePayoutService $payoutService;
    private EntityManagerInterface $em;
    private UrlGeneratorInterface $urlGenerator;

    public function __construct(
        CoinbaseService $coinbase,
        CoinbasePayoutService $payoutService,
        EntityManagerInterface $em,
        UrlGeneratorInterface $urlGenerator
    ) {
        $this->coinbase = $coinbase;
        $this->payoutService = $payoutService;
        $this->em = $em;
        $this->urlGenerator = $urlGenerator;
    }

    private function calculateWithdrawalFees(float $amount): float
    {
        if ($amount <= 20) {
            $percentage = 0.05;
        } elseif ($amount <= 100) {
            $percentage = 0.03;
        } elseif ($amount <= 500) {
            $percentage = 0.02;
        } else {
            $percentage = 0.01;
        }

        $fee = $amount * $percentage;
        return max($fee, 1.0);
    }

    #[Route('/withdraw', name: 'app_withdraw', methods: ['POST'])]
    public function withdraw(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $amount = (float) $request->request->get('amount');
        $currency = strtoupper(trim($request->request->get('currency')));
        $address = trim($request->request->get('recipient'));

        if ($amount < 2) {
            $this->addFlash('danger', 'Le montant minimum de retrait est de 2 USD.');
            return $this->redirectToRoute('app_profile');
        }

        $fees = $this->calculateWithdrawalFees($amount);
        $totalAmount = $amount + $fees;

        if ($totalAmount > $user->getBalance()) {
            $this->addFlash('danger', sprintf(
                'Solde insuffisant. Vous avez besoin de %.2f USD (dont %.2f USD de frais).',
                $totalAmount,
                $fees
            ));
            return $this->redirectToRoute('app_profile');
        }

        $transaction = new Transactions();
        $transaction->setUser($user)
            ->setType('withdrawal')
            ->setAmount($amount)
            ->setFees($fees)
            ->setMethod('coinbase_payout')
            ->setStatus('pending')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($transaction);

        $user->setBalance($user->getBalance() - $totalAmount);
        $this->em->persist($user);
        $this->em->flush();

        try {
            $result = $this->payoutService->createPayout(
                $currency,
                $amount,
                $address,
                (string) $transaction->getId()
            );

            $transaction->setExternalId($result['id']);
            $transaction->setStatus('completed');
            $this->em->flush();

            $this->addFlash('success', sprintf(
                'Retrait de %.2f %s effectué avec succès.',
                $amount,
                $currency
            ));
        } catch (\Exception $e) {
            $user->setBalance($user->getBalance() + $totalAmount);
            $transaction->setStatus('failed');
            $this->em->flush();

            $this->addFlash('danger', 'Erreur lors du retrait : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_profile');
    }

    // --- Dépôts ---
    #[Route('/deposit', name: 'app_deposit', methods: ['POST'])]
    public function deposit(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $amount = (float) $request->request->get('amount');
        $paymentMethod = $request->request->get('paymentMethod');

        if ($amount <= 0) {
            $this->addFlash('danger', 'Veuillez saisir un montant valide.');
            return $this->redirectToRoute('app_profile');
        }

        switch ($paymentMethod) {
            case 'paypal':
                return $this->redirectToRoute('app_paypal_redirect', ['amount' => $amount]);
            case 'crypto':
                return $this->redirectToRoute('app_crypto_redirect', ['amount' => $amount]);
            default:
                $this->addFlash('danger', 'Méthode de paiement invalide.');
                return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/deposit/crypto', name: 'app_crypto_redirect', methods: ['GET', 'POST'])]
    public function cryptoRedirect(Request $request): Response
    {
        $amount = (float) $request->query->get('amount');

        if ($request->isMethod('GET')) {
            return $this->render('payment/crypto_deposit.html.twig', ['amount' => $amount]);
        }

        // POST
        $cryptoType = strtoupper(trim($request->request->get('cryptoType')));
        $walletAddress = trim($request->request->get('walletAddress'));

        if (!$cryptoType || !$walletAddress) {
            $this->addFlash('danger', 'Veuillez sélectionner la crypto et saisir votre adresse.');
            return $this->redirectToRoute('app_crypto_redirect', ['amount' => $amount]);
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $transaction = new Transactions();
        $transaction->setUser($user)
            ->setType('deposit')
            ->setAmount($amount)
            ->setMethod(sprintf('coinbase_charge (%s)', $cryptoType))
            ->setStatus('pending')
            ->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($transaction);
        $this->em->flush();

        $redirectUrl = $this->urlGenerator->generate('payment_success', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $cancelUrl   = $this->urlGenerator->generate('payment_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $charge = $this->coinbase->createCharge(
            'Dépôt DCSM #' . $transaction->getId(),
            $walletAddress,
            $amount,
            'USD',
            $redirectUrl,
            $cancelUrl,
            $user->getEmail()
        );

        return $this->redirect($charge['data']['hosted_url']);
    }

    #[Route('/webhook/coinbase', name: 'webhook_coinbase', methods: ['POST'])]
    public function webhook(Request $request): Response
    {
        $payload = $request->getContent();
        $sigHeader = $request->headers->get('X-CC-Webhook-Signature');
        $secret = $this->getParameter('coinbase.webhook_secret');

        if (!hash_equals(hash_hmac('sha256', $payload, $secret), $sigHeader)) {
            return new Response('Signature invalide', 400);
        }

        $data = json_decode($payload, true);
        if ($data['event']['type'] === 'charge:confirmed') {
            $meta = $data['event']['data']['metadata'];
            $txId = (int) $meta['custom_id'];
            $tx = $this->em->getRepository(Transactions::class)->find($txId);

            if ($tx && $tx->getStatus() !== 'completed') {
                $tx->setStatus('completed');
                $user = $tx->getUser();
                $user->setBalance($user->getBalance() + $tx->getAmount());
                $this->em->persist($user);
                $this->em->persist($tx);
                $this->em->flush();
            }
        }

        return new Response('OK', 200);
    }

    #[Route('/success', name: 'payment_success')]
    public function success(): Response
    {
        $this->addFlash('success', 'Paiement reçu avec succès.');
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/cancel', name: 'payment_cancel')]
    public function cancel(): Response
    {
        $this->addFlash('warning', 'Paiement annulé.');
        return $this->redirectToRoute('app_profile');
    }
}