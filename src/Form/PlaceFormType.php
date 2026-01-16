<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PlaceFormData>
 */
class PlaceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Nazev',
            'attr' => [
                'placeholder' => 'Nazev mista',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('address', TextType::class, [
            'label' => 'Adresa',
            'attr' => [
                'placeholder' => 'Ulice a cislo popisne',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('city', TextType::class, [
            'label' => 'Mesto',
            'attr' => [
                'placeholder' => 'Praha',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('postalCode', TextType::class, [
            'label' => 'PSC',
            'attr' => [
                'placeholder' => '110 00',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('description', TextareaType::class, [
            'label' => 'Popis',
            'required' => false,
            'attr' => [
                'placeholder' => 'Volitelny popis mista',
                'class' => 'textarea textarea-bordered w-full',
                'rows' => 4,
            ],
        ]);

        $builder->add('mapImage', FileType::class, [
            'label' => 'Mapa skladu',
            'required' => false,
            'attr' => [
                'accept' => 'image/jpeg,image/png,image/webp',
                'class' => 'file-input file-input-bordered w-full',
            ],
            'help' => 'Obrazek mapy skladu (JPEG, PNG, WebP, max 5 MB)',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlaceFormData::class,
        ]);
    }
}
