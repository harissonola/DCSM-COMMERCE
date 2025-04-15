<?php

namespace App\Form;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fname', null, [
                'attr' => [
                    'placeholder' => "NOM",
                ],
                "label" => false,
            ])
            ->add('lname', null, [
                'attr' => [
                    'placeholder' => "PRENOMS",
                ],
                "label" => false,
            ])
            ->add('username', null, [
                'attr' => [
                    'placeholder' => "USERNAME",
                ],
                "label" => false,
            ])
            ->add('email', null, [
                'attr' => [
                    'placeholder' => "EMAIL",
                ],
                "label" => false,
            ])
            ->add('country', CountryType::class, [
                'placeholder' => 'Sélectionnez votre pays',
                "label" => false,
            ])
            ->add('photo', FileType::class, [
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'accept' => "image/jpeg, image/png, image/jpg, image/webp",
                    'class' => "form-control",
                ],
                "label" => false,
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'mapped' => false,
                'constraints' => [
                    new IsTrue([
                        'message' => 'You should agree to our terms.',
                    ]),
                ],
                'label_attr' => [
                    'class' => "text-uppercase text-light",
                ],
                "label" => "J'accepte les Termes et Conditions"
            ])
            ->add('plainPassword', PasswordType::class, [
                'mapped' => false,
                'attr' => [
                    'autocomplete' => 'new-password',
                    'placeholder' => 'Mot de Passe',
                ],
                'constraints' => [
                    new NotBlank(),
                    new Length([
                        'min' => 6,
                        'max' => 4096,
                    ]),
                ],
                'label' => false,
            ])

            ->add('confirmPassword', PasswordType::class, [
                'mapped' => false,
                'attr' => [
                    'placeholder' => 'Confirmer Mot de Passe',
                ],
                'constraints' => [
                    new NotBlank(),
                    new Length([
                        'min' => 6,
                        'max' => 4096,
                    ]),
                ],
                'label' => false,
            ])

            ->add('referredBy', TextType::class, [
                'attr' => [
                    'placeholder' => "Code de Parrainage",
                ],
                'required' => false,
                "label" => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
        ]);
    }
}
