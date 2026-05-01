<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PlaceProposeFormData>
 */
class PlaceProposeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Název',
            'attr' => ['placeholder' => 'Název místa'],
        ]);

        $builder->add('address', TextType::class, [
            'label' => 'Adresa',
            'required' => false,
            'attr' => ['placeholder' => 'Ulice a číslo popisné'],
        ]);

        $builder->add('city', TextType::class, [
            'label' => 'Město',
            'attr' => ['placeholder' => 'Praha'],
        ]);

        $builder->add('postalCode', TextType::class, [
            'label' => 'PSČ',
            'attr' => ['placeholder' => '110 00'],
        ]);

        $builder->add('description', TextareaType::class, [
            'label' => 'Popis',
            'required' => false,
            'attr' => [
                'placeholder' => 'Volitelný popis místa',
                'rows' => 4,
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlaceProposeFormData::class,
        ]);
    }
}
