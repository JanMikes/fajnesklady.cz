<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<StorageTypeFormData>
 */
class StorageTypeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Nazev',
            'attr' => [
                'placeholder' => 'Nazev typu skladu',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        // Inner dimensions (required)
        $builder->add('innerWidth', IntegerType::class, [
            'label' => 'Vnitrni sirka (cm)',
            'attr' => [
                'placeholder' => '200',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('innerHeight', IntegerType::class, [
            'label' => 'Vnitrni vyska (cm)',
            'attr' => [
                'placeholder' => '250',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('innerLength', IntegerType::class, [
            'label' => 'Vnitrni delka (cm)',
            'attr' => [
                'placeholder' => '300',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        // Outer dimensions (optional)
        $builder->add('outerWidth', IntegerType::class, [
            'label' => 'Vnejsi sirka (cm)',
            'required' => false,
            'attr' => [
                'placeholder' => '210',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('outerHeight', IntegerType::class, [
            'label' => 'Vnejsi vyska (cm)',
            'required' => false,
            'attr' => [
                'placeholder' => '260',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('outerLength', IntegerType::class, [
            'label' => 'Vnejsi delka (cm)',
            'required' => false,
            'attr' => [
                'placeholder' => '310',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('defaultPricePerWeek', NumberType::class, [
            'label' => 'Vychozi cena za tyden (CZK)',
            'scale' => 2,
            'attr' => [
                'placeholder' => '500.00',
                'class' => 'input input-bordered w-full',
                'step' => '0.01',
            ],
        ]);

        $builder->add('defaultPricePerMonth', NumberType::class, [
            'label' => 'Vychozi cena za mesic (CZK)',
            'scale' => 2,
            'attr' => [
                'placeholder' => '1500.00',
                'class' => 'input input-bordered w-full',
                'step' => '0.01',
            ],
        ]);

        $builder->add('description', TextareaType::class, [
            'label' => 'Popis',
            'required' => false,
            'attr' => [
                'placeholder' => 'Volitelny popis typu skladu',
                'class' => 'textarea textarea-bordered w-full',
                'rows' => 3,
            ],
        ]);

        $builder->add('photos', FileType::class, [
            'label' => 'Fotografie',
            'required' => false,
            'multiple' => true,
            'attr' => [
                'accept' => 'image/jpeg,image/png,image/webp',
                'class' => 'file-input file-input-bordered w-full',
            ],
            'help' => 'Nahrajte fotografie skladu (JPEG, PNG, WebP, max 5 MB kazda)',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StorageTypeFormData::class,
        ]);
    }
}
