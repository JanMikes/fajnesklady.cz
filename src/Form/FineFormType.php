<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\FineType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<FineFormData>
 */
final class FineFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', EnumType::class, [
                'class' => FineType::class,
                'choice_label' => static fn (FineType $type): string => $type->label(),
                'label' => 'Typ pokuty',
                'placeholder' => 'Vyberte typ pokuty',
            ])
            ->add('amountInCzk', NumberType::class, [
                'label' => 'Částka (Kč)',
                'scale' => 2,
                'attr' => [
                    'data-fine-form-target' => 'amount',
                    'inputmode' => 'decimal',
                    'min' => 0.01,
                    'step' => '0.01',
                ],
            ])
            ->add('nonReturnDays', IntegerType::class, [
                'label' => 'Počet dní nevrácení',
                'required' => false,
                'attr' => [
                    'data-fine-form-target' => 'nonReturnDays',
                    'min' => 1,
                ],
            ])
            ->add('latePaymentBaseInCzk', NumberType::class, [
                'label' => 'Základ dluhu (Kč)',
                'scale' => 2,
                'required' => false,
                'attr' => [
                    'data-fine-form-target' => 'latePaymentBase',
                    'inputmode' => 'decimal',
                    'min' => 0.01,
                    'step' => '0.01',
                ],
            ])
            ->add('latePaymentDays', IntegerType::class, [
                'label' => 'Počet dní prodlení',
                'required' => false,
                'attr' => [
                    'data-fine-form-target' => 'latePaymentDays',
                    'min' => 1,
                ],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Popis / poznámka (vidí zákazník)',
                'empty_data' => '',
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'Důvod vystavení pokuty...',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FineFormData::class,
            'csrf_protection' => false,
        ]);
    }
}
