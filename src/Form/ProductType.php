<?php
// src/Form/ProductType.php
namespace App\Form;

use App\Entity\Product;
use App\Entity\Shop;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom du produit'
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug (URL)',
                'required' => false
            ])
            ->add('price', MoneyType::class, [
                'label' => 'Prix',
                'currency' => 'USD'
            ])
            ->add('shop', EntityType::class, [
                'class' => Shop::class,
                'choice_label' => 'name',
                'label' => 'Boutique',
                'placeholder' => 'Sélectionnez une boutique',
                'required' => false
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false
            ])
            ->add('Image', TextType::class, [
                'label' => 'Image (URL)',
                'required' => false
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
        ]);
    }
}