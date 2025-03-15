<?php

namespace App\Controller;

use App\Entity\Transactions;
use App\Repository\TransactionsRepository;
use App\Service\CryptoService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile')]
    public function index(TransactionsRepository $transactionsRepository): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        $transactions = $transactionsRepository->findBy(['user' => $this->getUser()]);

        return $this->render('profile/index.html.twig', [
            'transactions' => $transactions,
        ]);
    }

    // Dépôt en USDT (TRC20) ou autres méthodes de paiement
    #[Route('/profile/deposit', name: 'app_deposit', methods: ['POST'])]
    public function deposit(
        Request $request, 
        EntityManagerInterface $em, 
        CryptoService $cryptoService, 
        LoggerInterface $logger
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $amount = (float) $request->request->get('amount');
        $paymentMethod = $request->request->get('paymentMethod'); // Ce champ doit être présent dans ton formulaire

        if ($amount <= 0) {
            $this->addFlash('error', 'Le montant doit être positif.');
            return $this->redirectToRoute('app_profile');
        }

        if ($paymentMethod === 'crypto') {
            // Traitement pour la méthode Crypto
            try {
                // Adresse du portefeuille de la plateforme
                $address = 'TLQMEec1F5zJuHXsgKWfbUqEHXWj9p5KkV';
                $response = $cryptoService->verifyDeposit($address, $amount);

                if ($response['status'] === 'success') {
                    $em->beginTransaction();
                    
                    $transaction = new Transactions();
                    $transaction->setUser($user);
                    $transaction->setMethod('Dépôt Crypto');
                    $transaction->setAmount($amount);
                    $transaction->setCreatedAt(new \DateTimeImmutable());

                    $user->setBalance($user->getBalance() + $amount);

                    $em->persist($transaction);
                    $em->flush();
                    $em->commit();

                    $this->addFlash('success', 'Dépôt Crypto effectué avec succès.');
                } else {
                    $this->addFlash('error', 'Dépôt non vérifié : ' . $response['message']);
                }
            } catch (\Exception $e) {
                $em->rollback();
                $logger->error('Erreur lors de la vérification du dépôt Crypto : ' . $e->getMessage());
                $this->addFlash('error', 'Erreur lors de la vérification du dépôt.');
            }
        } else {
            // Traitement pour les autres méthodes de paiement (ex : PayPal, Mobile Money, Carte)
            // Ici, tu devras intégrer l'API externe correspondante ou rediriger l'utilisateur
            // vers une page de paiement. Pour cet exemple, on simule un paiement réussi.
            $this->addFlash('info', "La procédure de paiement par " . ucfirst($paymentMethod) . " a été initiée.");

            try {
                $em->beginTransaction();
                
                $transaction = new Transactions();
                $transaction->setUser($user);
                $transaction->setMethod('Dépôt ' . ucfirst($paymentMethod));
                $transaction->setAmount($amount);
                $transaction->setCreatedAt(new \DateTimeImmutable());

                // Ici, après confirmation externe, tu mettrais à jour le solde utilisateur
                $user->setBalance($user->getBalance() + $amount);

                $em->persist($transaction);
                $em->flush();
                $em->commit();

                $this->addFlash('success', "Dépôt via " . ucfirst($paymentMethod) . " effectué avec succès.");
            } catch (\Exception $e) {
                $em->rollback();
                $logger->error('Erreur lors du dépôt ' . $paymentMethod . ' : ' . $e->getMessage());
                $this->addFlash('error', 'Erreur lors de la demande de dépôt via ' . ucfirst($paymentMethod) . '.');
            }
        }

        return $this->redirectToRoute('app_profile');
    }

    // Retrait en USDT (TRC20)
    #[Route('/profile/withdraw', name: 'app_withdraw', methods: ['POST'])]
    public function withdraw(
        Request $request, 
        EntityManagerInterface $em, 
        CryptoService $cryptoService, 
        LoggerInterface $logger
    ): Response {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $amount = (float) $request->request->get('amount');
        $cryptoAddress = trim($request->request->get('cryptoAddress'));
        
        if ($amount <= 0) {
            $this->addFlash('error', 'Le montant doit être positif.');
            return $this->redirectToRoute('app_profile');
        }

        if ($amount > $user->getBalance()) {
            $this->addFlash('error', 'Solde insuffisant.');
            return $this->redirectToRoute('app_profile');
        }

        if (empty($cryptoAddress)) {
            $this->addFlash('error', 'Veuillez fournir une adresse de réception.');
            return $this->redirectToRoute('app_profile');
        }

        try {
            $response = $cryptoService->withdraw($amount, $cryptoAddress);

            if ($response['status'] === 'success') {
                $em->beginTransaction();
                
                $transaction = new Transactions();
                $transaction->setUser($user);
                $transaction->setMethod('Retrait');
                $transaction->setAmount(-$amount);
                $transaction->setCreatedAt(new \DateTimeImmutable());

                $user->setBalance($user->getBalance() - $amount);

                $em->persist($transaction);
                $em->flush();
                $em->commit();

                $this->addFlash('success', 'Retrait effectué avec succès.');
            } else {
                $this->addFlash('error', 'Échec du retrait : ' . $response['message']);
            }
        } catch (\Exception $e) {
            $em->rollback();
            $logger->error('Erreur lors de la demande de retrait : ' . $e->getMessage());
            $this->addFlash('error', 'Erreur lors de la demande de retrait.');
        }

        return $this->redirectToRoute('app_profile');
    }
}