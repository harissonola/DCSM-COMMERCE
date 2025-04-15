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
        $passwordConstraints = [];
        if ($options['is_new']) {
            $passwordConstraints = [
                new NotBlank([
                    'message' => 'Veuillez entrer un mot de passe',
                ]),
                new Length([
                    'min' => 6,
                    'minMessage' => 'Votre mot de passe doit contenir au moins {{ limit }} caractères',
                    'max' => 4096,
                ]),
            ];
        }

        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'attr' => ['placeholder' => 'Email de l\'utilisateur']
            ])
            ->add('username', TextType::class, [
                'label' => 'Nom d\'utilisateur',
                'attr' => ['placeholder' => 'Nom d\'utilisateur']
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Mot de passe',
                'mapped' => false,
                'required' => $options['is_new'],
                'attr' => ['autocomplete' => 'new-password'],
                'constraints' => $passwordConstraints,
            ])
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
            ])
            ->add('roles', ChoiceType::class, [
                'label' => 'Rôles',
                'choices' => [
                    'Administrateur' => 'ROLE_ADMIN',
                    'Utilisateur' => 'ROLE_USER',
                ],
                'multiple' => true,
                'expanded' => true,
            ])
            ->add('isVerified', CheckboxType::class, [
                'label' => 'Email vérifié ?',
                'required' => false
            ])
            ->add('balance', NumberType::class, [
                'label' => 'Solde',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Solde de l\'utilisateur',
                    'step' => '0.01'
                ],
                'html5' => true
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