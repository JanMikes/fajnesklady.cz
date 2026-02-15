<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\PlaceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
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
        $builder->add('type', EnumType::class, [
            'class' => PlaceType::class,
            'label' => 'Typ mista',
            'choice_label' => fn (PlaceType $type) => $type->label(),
        ]);

        $builder->add('name', TextType::class, [
            'label' => 'Nazev',
            'attr' => [
                'placeholder' => 'Nazev mista',
            ],
        ]);

        $builder->add('useMapLocation', CheckboxType::class, [
            'required' => false,
            'label' => 'Misto nema adresu, vybrat na mape',
        ]);

        $builder->add('address', TextType::class, [
            'label' => 'Adresa',
            'required' => false,
            'attr' => [
                'placeholder' => 'Ulice a cislo popisne',
            ],
        ]);

        $builder->add('city', TextType::class, [
            'label' => 'Mesto',
            'attr' => [
                'placeholder' => 'Praha',
            ],
        ]);

        $builder->add('postalCode', TextType::class, [
            'label' => 'PSC',
            'attr' => [
                'placeholder' => '110 00',
            ],
        ]);

        $builder->add('latitude', TextType::class, [
            'label' => 'Zeměpisná šířka',
            'required' => false,
            'attr' => [
                'placeholder' => '49.7437572',
                'inputmode' => 'decimal',
            ],
        ]);

        $builder->add('longitude', TextType::class, [
            'label' => 'Zeměpisná délka',
            'required' => false,
            'attr' => [
                'placeholder' => '13.3799330',
                'inputmode' => 'decimal',
            ],
        ]);

        $builder->add('description', TextareaType::class, [
            'label' => 'Popis',
            'required' => false,
            'attr' => [
                'placeholder' => 'Volitelny popis mista',

                'rows' => 4,
            ],
        ]);

        $builder->add('mapImage', FileType::class, [
            'label' => 'Mapa skladu',
            'required' => false,
            'attr' => [
                'accept' => 'image/jpeg,image/png,image/webp',
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
