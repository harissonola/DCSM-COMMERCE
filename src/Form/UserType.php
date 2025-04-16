<?php
// src/Form/UserType.php
namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class UserType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr' => ['placeholder' => 'Email de l\'utilisateur']
            ])
            ->add('username', TextType::class, [
                'label' => 'Nom d\'utilisateur',
                'attr' => ['placeholder' => 'Nom d\'utilisateur']
            ]);

        // Ajouter le champ password seulement pour la création
        if ($options['is_new']) {
            $builder->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'mapped' => false,
                'required' => true,
                'attr' => [
                    'autocomplete' => 'new-password',
                    'placeholder' => 'Mot de passe'
                ],
                'constraints' => [
                    new NotBlank([
                        'message' => 'Veuillez entrer un mot de passe',
                    ]),
                    new Length([
                        'min' => 6,
                        'minMessage' => 'Votre mot de passe doit contenir au moins {{ limit }} caractères',
                        'max' => 4096,
                    ]),
                ],
            ]);
        }

        $builder
            ->add('fname', TextType::class, [
                'label' => 'Prénom',
                'attr' => ['placeholder' => 'Prénom']
            ])
            ->add('lname', TextType::class, [
                'label' => 'Nom',
                'attr' => ['placeholder' => 'Nom']
            ])
            ->add('country', CountryType::class, [
                'label' => 'Pays',
                'placeholder' => 'Sélectionnez un pays'
            ]);

        // Ajouter le champ balance seulement pour l'édition
        if (!$options['is_new']) {
            $builder->add('balance', NumberType::class, [
                'label' => 'Solde (USD)',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Montant en USD',
                    'step' => '0.01'
                ],
                'html5' => true
            ]);
        }

        $builder
            ->add('roles', ChoiceType::class, [
                'label' => 'Rôles',
                'choices' => [
                    'Administrateur' => 'ROLE_ADMIN',
                    'Utilisateur' => 'ROLE_USER',
                ],
                'multiple' => true,
                'expanded' => true,
            ])
            ->add('verified', CheckboxType::class, [
                'label' => 'Email vérifié ?',
                'required' => false,
                'property_path' => 'verified' // Cela utilisera setVerified() au lieu de setIsVerified()
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'is_new' => true,
        ]);
        
        $resolver->setAllowedTypes('is_new', 'bool');
    }
}