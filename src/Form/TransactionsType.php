<?php
// src/Form/TransactionsType.php
namespace App\Form;

use App\Entity\Transactions;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class TransactionsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('user', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'email',
                'label' => 'Utilisateur'
            ])
            ->add('method', ChoiceType::class, [
                'label' => 'Méthode de paiement',
                'choices' => [
                    'Carte bancaire' => 'card',
                    'PayPal' => 'paypal',
                    'Virement' => 'transfer',
                    'Crypto' => 'crypto'
                ]
            ])
            ->add('amount', MoneyType::class, [
                'label' => 'Montant',
                'currency' => 'EUR',
                'required' => false
            ])
            ->add('status', ChoiceType::class, [
                'label' => 'Statut',
                'choices' => [
                    'En attente' => 'pending',
                    'Complétée' => 'completed',
                    'Échouée' => 'failed',
                    'Remboursée' => 'refunded'
                ],
                'required' => false
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Type',
                'choices' => [
                    'Dépôt' => 'deposit',
                    'Retrait' => 'withdrawal',
                    'Achat' => 'purchase'
                ],
                'required' => false
            ])
            ->add('ExternalId', TextType::class, [
                'label' => 'ID Externe',
                'required' => false
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Transactions::class,
        ]);
    }
}