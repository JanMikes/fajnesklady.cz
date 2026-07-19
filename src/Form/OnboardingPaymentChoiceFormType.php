<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\PriceCalculator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<OnboardingPaymentChoiceFormData>
 */
final class OnboardingPaymentChoiceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $rentalDays = $options['rental_days'];
        \assert(is_int($rentalDays));

        // Fixed rental window (from the order) → static choices, no date-driven
        // reconfiguration like OrderFormType needs.
        $frequencyChoices = [PaymentFrequency::MONTHLY->label() => PaymentFrequency::MONTHLY];
        if ($rentalDays >= PriceCalculator::YEARLY_THRESHOLD_DAYS) {
            $frequencyChoices[PaymentFrequency::YEARLY->label()] = PaymentFrequency::YEARLY;
        }
        if ($rentalDays >= PriceCalculator::WEEKLY_THRESHOLD_DAYS) {
            $frequencyChoices[PaymentFrequency::ONE_TIME->label()] = PaymentFrequency::ONE_TIME;
        }

        $cardEligible = $rentalDays >= PriceCalculator::WEEKLY_THRESHOLD_DAYS;

        $builder
            ->add('paymentMethod', EnumType::class, [
                'class' => PaymentMethod::class,
                'label' => 'Způsob platby',
                'expanded' => true,
                'placeholder' => false,
                'choices' => [
                    'Platba kartou (GoPay)' => PaymentMethod::GOPAY,
                    'Bankovní převod' => PaymentMethod::BANK_TRANSFER,
                ],
                // Progressive enhancement only — the matrix violation in the
                // FormData stays the gate.
                'choice_attr' => static fn (PaymentMethod $method): array => !$cardEligible && PaymentMethod::GOPAY === $method
                    ? ['disabled' => 'disabled']
                    : [],
            ])
            ->add('paymentFrequency', EnumType::class, [
                'class' => PaymentFrequency::class,
                'label' => 'Frekvence platby',
                'expanded' => true,
                'placeholder' => false,
                'choices' => $frequencyChoices,
            ]);

        // Card = always automatic monthly recurring (spec 076): the frequency
        // card is hidden for GoPay, so a stale 'one_time'/'yearly' would submit
        // invisibly and trip the matrix violation on a field the user can't see.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $raw = $event->getData();
            if (!is_array($raw)) {
                return;
            }

            if (PaymentMethod::GOPAY->value === ($raw['paymentMethod'] ?? null)
                && PaymentFrequency::MONTHLY->value !== ($raw['paymentFrequency'] ?? null)) {
                $raw['paymentFrequency'] = PaymentFrequency::MONTHLY->value;
                $event->setData($raw);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OnboardingPaymentChoiceFormData::class,
            'csrf_protection' => false,
        ]);
        $resolver->setRequired('rental_days');
        $resolver->setAllowedTypes('rental_days', 'int');
    }
}
