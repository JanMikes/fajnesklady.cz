<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\FineType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
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
            ->add('amountInHaler', IntegerType::class, [
                'label' => 'Částka (v haléřích)',
                'attr' => [
                    'data-fine-form-target' => 'amount',
                    'min' => 1,
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
            ->add('latePaymentBaseInHaler', IntegerType::class, [
                'label' => 'Základ dluhu (v haléřích)',
                'required' => false,
                'attr' => [
                    'data-fine-form-target' => 'latePaymentBase',
                    'min' => 1,
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
        ]);
    }
}
