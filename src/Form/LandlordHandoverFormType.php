<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\FineType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<LandlordHandoverFormData>
 */
final class LandlordHandoverFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('comment', TextareaType::class, [
                'label' => 'Komentář',
                'empty_data' => '',
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'Popište stav skladu při převzetí...',
                ],
            ])
            ->add('newLockCode', TextType::class, [
                'label' => 'Nový kód zámku',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Zadejte kód zámku pro dalšího nájemce',
                ],
            ])
            // Optional inline fine (spec 080). All fine fields are required=false at
            // the form level — requiredness is enforced conditionally by
            // LandlordHandoverFormData::validateFine() only when issueFine is checked.
            ->add('issueFine', CheckboxType::class, [
                'label' => 'Vystavit smluvní pokutu',
                'required' => false,
            ])
            ->add('fineType', EnumType::class, [
                'class' => FineType::class,
                'choice_label' => static fn (FineType $type): string => $type->label(),
                'label' => 'Typ pokuty',
                'placeholder' => 'Vyberte typ pokuty',
                'required' => false,
            ])
            ->add('fineAmountInCzk', NumberType::class, [
                'label' => 'Částka (Kč)',
                'scale' => 2,
                'required' => false,
                'attr' => [
                    'data-fine-form-target' => 'amount',
                    'inputmode' => 'decimal',
                    'min' => 0.01,
                    'step' => '0.01',
                ],
            ])
            ->add('fineNonReturnDays', IntegerType::class, [
                'label' => 'Počet dní nevrácení',
                'required' => false,
                'attr' => [
                    'data-fine-form-target' => 'nonReturnDays',
                    'min' => 1,
                ],
            ])
            ->add('fineLatePaymentBaseInCzk', NumberType::class, [
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
            ->add('fineLatePaymentDays', IntegerType::class, [
                'label' => 'Počet dní prodlení',
                'required' => false,
                'attr' => [
                    'data-fine-form-target' => 'latePaymentDays',
                    'min' => 1,
                ],
            ])
            ->add('fineDescription', TextareaType::class, [
                'label' => 'Popis / poznámka (vidí zákazník)',
                'empty_data' => '',
                'required' => false,
                'attr' => [
                    'rows' => 4,
                    'placeholder' => 'Důvod vystavení pokuty...',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LandlordHandoverFormData::class,
            'csrf_protection' => false,
        ]);
    }
}
