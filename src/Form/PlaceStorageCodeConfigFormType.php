<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PlaceStorageCodeConfigFormData>
 */
final class PlaceStorageCodeConfigFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('enabled', CheckboxType::class, [
                'label' => 'Povolit přístupové kódy',
                'required' => false,
            ])
            ->add('digits', IntegerType::class, [
                'label' => 'Počet číslic',
                'attr' => [
                    'min' => 1,
                    'max' => 10,
                    'inputmode' => 'numeric',
                ],
            ])
            ->add('from', IntegerType::class, [
                'label' => 'Od',
                'attr' => [
                    'min' => 0,
                    'inputmode' => 'numeric',
                ],
            ])
            ->add('to', IntegerType::class, [
                'label' => 'Do',
                'attr' => [
                    'min' => 0,
                    'inputmode' => 'numeric',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlaceStorageCodeConfigFormData::class,
        ]);
    }
}
