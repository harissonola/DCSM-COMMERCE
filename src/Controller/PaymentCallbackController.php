<?php

namespace App\Controller;

use App\Entity\Transactions;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PaymentCallbackController extends AbstractController
{
    /**
     * Ce contrôleur reçoit les callbacks du prestataire de paiement.
     * Il doit vérifier les données envoyées, valider le paiement et mettre à jour la transaction
     * ainsi que le solde de l'utilisateur.
     */
    #[Route('/payment/callback', name: 'app_payment_callback', methods: ['POST'])]
    public function paymentCallback(Request $request, EntityManagerInterface $em): Response
    {
        // Récupérer toutes les données envoyées par le prestataire
        $data = $request->request->all();

        // Exemple de données attendues :
        // - transaction_id : identifiant de la transaction généré par ton système ou par le prestataire
        // - status         : statut du paiement (ex: "completed", "failed", "pending", etc.)
        // - amount         : montant payé
        // - user_id        : identifiant de l'utilisateur concerné
        // - signature      : signature pour vérifier l'authenticité (optionnel selon l'API)
        
        // Vérification minimale des données reçues
        if (!isset($data['transaction_id'], $data['status'], $data['amount'], $data['user_id'])) {
            return new Response('Données invalides', Response::HTTP_BAD_REQUEST);
        }
        
        // Optionnel : Vérifier la signature pour assurer l'authenticité du callback
        // $expectedSignature = hash_hmac('sha256', $data['transaction_id'].$data['amount'], 'TA_CLE_SECRETE');
        // if ($data['signature'] !== $expectedSignature) {
        //     return new Response('Signature invalide', Response::HTTP_FORBIDDEN);
        // }

        // Récupérer l'utilisateur concerné
        $user = $em->getRepository(User::class)->find($data['user_id']);
        if (!$user) {
            return new Response('Utilisateur non trouvé', Response::HTTP_NOT_FOUND);
        }

        // On peut rechercher une transaction existante ou créer une nouvelle
        $transaction = new Transactions();
        $transaction->setUser($user);
        $transaction->setMethod('Dépôt externe');
        $transaction->setAmount($data['amount']);
        $transaction->setCreatedAt(new \DateTimeImmutable());
        // Tu peux ajouter d'autres champs comme transaction_id, status, etc.
        
        // Mise à jour du solde de l'utilisateur en fonction du statut du paiement
        if ($data['status'] === 'completed') {
            $user->setBalance($user->getBalance() + $data['amount']);
            // Tu peux aussi enregistrer un statut "validé" dans la transaction.
        } else {
            // Gérer les autres cas : paiement en échec, en attente, etc.
            // Par exemple, tu pourrais enregistrer le statut dans la transaction sans mettre à jour le solde.
        }

        // Persistance en base
        try {
            $em->persist($transaction);
            $em->flush();
        } catch (\Exception $e) {
            return new Response('Erreur lors du traitement: ' . $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        
        // Répondre au prestataire pour confirmer la bonne réception du callback
        return new Response('Callback traité', Response::HTTP_OK);
    }
}