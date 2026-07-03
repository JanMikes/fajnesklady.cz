<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\PriceCalculator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AdminOnboardingFormData>
 */
final class AdminOnboardingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Admin enters the *customer's* identity here. Native types stay (email/tel/date
            // keyboards + validation), but browser autofill is suppressed on every identity
            // field so the browser neither injects the admin's own saved data nor offers to
            // save the customer's data under the admin's profile.
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'empty_data' => '',
                'attr' => ['placeholder' => 'zakaznik@example.com', 'autocomplete' => 'off'],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'Jméno',
                'empty_data' => '',
                'attr' => ['placeholder' => 'Jan', 'autocomplete' => 'off'],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Příjmení',
                'empty_data' => '',
                'attr' => ['placeholder' => 'Novák', 'autocomplete' => 'off'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Telefon',
                'required' => false,
                'attr' => ['placeholder' => '+420 123 456 789', 'autocomplete' => 'off'],
            ])
            ->add('birthDate', DateType::class, [
                'label' => 'Datum narození',
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
                    'autocomplete' => 'off',
                    'data-datepicker-max-date-value' => (new \DateTimeImmutable('today'))->modify('-18 years')->format('Y-m-d'),
                ],
            ])
            ->add('invoiceToCompany', CheckboxType::class, [
                'label' => 'Fakturovat na firmu',
                'required' => false,
            ])
            ->add('companyName', TextType::class, [
                'label' => 'Název firmy',
                'required' => false,
                'attr' => ['placeholder' => 'Firma s.r.o.', 'autocomplete' => 'off'],
            ])
            ->add('companyId', TextType::class, [
                'label' => 'IČO',
                'required' => false,
                'attr' => ['placeholder' => '12345678', 'maxlength' => 8, 'inputmode' => 'numeric', 'autocomplete' => 'off'],
            ])
            ->add('companyVatId', TextType::class, [
                'label' => 'DIČ',
                'required' => false,
                'attr' => ['placeholder' => 'CZ12345678', 'autocomplete' => 'off'],
            ])
            ->add('billingStreet', TextType::class, [
                'label' => 'Ulice a číslo popisné',
                'attr' => ['placeholder' => 'Hlavní 123'],
            ])
            ->add('billingCity', TextType::class, [
                'label' => 'Město',
                'attr' => ['placeholder' => 'Praha'],
            ])
            ->add('billingPostalCode', TextType::class, [
                'label' => 'PSČ',
                'attr' => ['placeholder' => '110 00', 'maxlength' => 10],
            ])
            ->add('addressOverride', CheckboxType::class, [
                'label' => 'Adresa je správná, pokračovat',
                'required' => false,
            ])
            ->add('startDate', DateType::class, [
                'label' => 'Datum začátku',
                'widget' => 'single_text',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Datum konce',
                // required: false keeps live per-field validation happy on partial
                // submits; AdminOnboardingFormData's NotNull is the source of truth.
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('paymentMethod', EnumType::class, [
                'class' => PaymentMethod::class,
                'label' => 'Způsob platby',
                'expanded' => true,
                'placeholder' => false,
                'choice_label' => static fn (PaymentMethod $method): string => match ($method) {
                    PaymentMethod::EXTERNAL => 'Externí platba (hotovost, jiné)',
                    PaymentMethod::GOPAY => 'GoPay (zákazník nastaví při podpisu)',
                    PaymentMethod::BANK_TRANSFER => 'Bankovní převod (zákazník platí převodem)',
                },
            ])
            ->add('paymentFrequency', EnumType::class, self::paymentFrequencyOptions([
                PaymentFrequency::MONTHLY->label() => PaymentFrequency::MONTHLY,
                PaymentFrequency::YEARLY->label() => PaymentFrequency::YEARLY,
            ]))
            ->add('monthlyPriceMode', ChoiceType::class, [
                'label' => 'Cenový model',
                'expanded' => true,
                'placeholder' => false,
                'choices' => [
                    'Standardní (sazba skladu)' => 'standard',
                    'Individuální měsíční cena' => 'custom',
                    'Zdarma (bez účtování)' => 'free',
                ],
            ])
            ->add('customMonthlyPriceInCzk', NumberType::class, [
                'label' => 'Individuální měsíční cena (Kč)',
                'required' => false,
                'scale' => 2,
                // NumberType scale 2 renders type=text; the decimal keypad helps on mobile.
                'attr' => ['placeholder' => '1500.00', 'inputmode' => 'decimal', 'autocomplete' => 'off'],
                'help' => 'Maximálně 15 000 Kč (zákonný strop pro opakované platby).',
            ])
            ->add('isExternallyPrepaid', CheckboxType::class, [
                'label' => 'Externí předplatné — zákazník již zaplatil mimo GoPay',
                'required' => false,
            ])
            ->add('paidThroughDate', DateType::class, [
                'label' => 'Předplaceno do',
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
                    'data-datepicker-min-date-value' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                ],
                'help' => 'Po vypršení předplatného bude zákazníkovi 7 dní předem zaslán e-mail s žádostí o nastavení automatické platby.',
            ])
            ->add('contractDocument', FileType::class, [
                'label' => 'Existující smlouva (volitelné)',
                'required' => false,
                'help' => 'Nahrajte podepsanou smlouvu (PDF, JPEG, PNG, max 10 MB). Zákazník pak podepisuje pouze VOP.',
            ])
            ->add('variableSymbol', TextType::class, [
                'label' => 'Variabilní symbol',
                'required' => false,
                'attr' => ['placeholder' => 'Ponechte prázdné pro automatické vygenerování', 'inputmode' => 'numeric', 'autocomplete' => 'off'],
                'help' => 'Pouze pro bankovní převod. Číselný, max 10 číslic.',
            ])
            ->add('debtAmountInCzk', NumberType::class, [
                'label' => 'Dluh z předchozí smlouvy (Kč)',
                'required' => false,
                'html5' => true,
                'attr' => ['placeholder' => '0', 'min' => 0, 'step' => 1, 'autocomplete' => 'off'],
                'help' => 'Pokud má zákazník nevyplacený dluh, zadejte částku v Kč. Zákazník musí dluh uhradit před zahájením nového pronájmu.',
            ]);

        // Date-dependent frequency choices (spec 078), mirroring OrderFormType:
        // the Jednorázová option appears at ≥ 31 days, Roční at ≥ 360 days. The
        // Live Component hydrates the model fresh (empty) and submits the raw
        // formValues, so PRE_SUBMIT is the hook that makes the radios reactive;
        // PRE_SET_DATA covers a possible pre-filled initial render.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $data = $event->getData();
            if (!$data instanceof AdminOnboardingFormData || null === $data->startDate) {
                return;
            }

            self::reconfigureFrequencyChoices($event->getForm(), $data->startDate, $data->endDate);
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $raw = $event->getData();
            if (!is_array($raw)) {
                return;
            }

            $startDate = self::parseSubmittedDate($raw['startDate'] ?? null);
            if (null === $startDate) {
                return;
            }

            self::reconfigureFrequencyChoices($event->getForm(), $startDate, self::parseSubmittedDate($raw['endDate'] ?? null));
        });
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private static function reconfigureFrequencyChoices(
        FormInterface $form,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): void {
        if (null === $endDate || $endDate <= $startDate) {
            return;
        }

        $days = (int) $startDate->diff($endDate)->days;

        $choices = [PaymentFrequency::MONTHLY->label() => PaymentFrequency::MONTHLY];
        if ($days >= PriceCalculator::YEARLY_THRESHOLD_DAYS) {
            $choices[PaymentFrequency::YEARLY->label()] = PaymentFrequency::YEARLY;
        }
        if ($days >= PriceCalculator::WEEKLY_THRESHOLD_DAYS) {
            $choices[PaymentFrequency::ONE_TIME->label()] = PaymentFrequency::ONE_TIME;
        }

        $form->add('paymentFrequency', EnumType::class, self::paymentFrequencyOptions($choices));
    }

    /**
     * @param array<string, PaymentFrequency> $choices
     *
     * @return array<string, mixed>
     */
    private static function paymentFrequencyOptions(array $choices): array
    {
        return [
            'class' => PaymentFrequency::class,
            'label' => 'Frekvence platby',
            'expanded' => true,
            'placeholder' => false,
            'choices' => $choices,
            'help' => 'Roční platba (−10 %) je dostupná pro pronájem na 12+ měsíců a platí se pouze bankovním převodem nebo externě; účtuje se ručně. Jednorázová platba předem (od 31 dnů) = celý pronájem jedním bankovním převodem.',
        ];
    }

    private static function parseSubmittedDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === trim($value)) {
            return null;
        }

        // DateType single_text (HTML5 date) submits 'Y-m-d'.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return false === $date ? null : $date;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminOnboardingFormData::class,
            'csrf_protection' => false,
        ]);
    }
}
