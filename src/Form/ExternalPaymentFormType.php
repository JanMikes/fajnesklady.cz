<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ExternalPaymentFormData>
 */
final class ExternalPaymentFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('coverage', ChoiceType::class, [
                'label' => 'Rozsah platby',
                'expanded' => true,
                'choices' => [
                    'Za celé období (posunout o jedno zúčtovací období)' => ExternalPaymentFormData::COVERAGE_WHOLE_CYCLE,
                    'Zaplaceno do konkrétního data' => ExternalPaymentFormData::COVERAGE_SPECIFIC_DATE,
                ],
            ])
            ->add('paidThroughDate', DateType::class, [
                'label' => 'Zaplaceno do',
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
                    'data-datepicker-min-date-value' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
                ],
                'help' => 'Vyplňte pouze při volbě „Zaplaceno do konkrétního data“.',
            ])
            ->add('amountInCzk', NumberType::class, [
                'label' => 'Částka (Kč vč. DPH)',
                'scale' => 2,
                'attr' => [
                    'inputmode' => 'decimal',
                    'min' => 0,
                    'step' => '0.01',
                ],
                'help' => 'Zaznamená se jako přijatá platba; při vystavení faktury je to fakturovaná částka.',
            ])
            ->add('issueInvoice', CheckboxType::class, [
                'label' => 'Vystavit fakturu',
                'required' => false,
                'help' => 'Vystaví a e-mailem odešle fakturu (vč. DPH) na tuto částku.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ExternalPaymentFormData::class,
            'csrf_protection' => false,
        ]);
    }
}
