<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\PriceCalculator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<OrderFormData>
 */
final class OrderFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // empty_data: '' is set explicitly on the three non-nullable string properties of
            // OrderFormData. Without this, Symfony Forms binds an empty input to the FormType's
            // default empty_data (null), and PropertyAccess fails to assign null to a `string`
            // property. The default value matters because per-field live validation re-submits
            // the form while other required fields are still empty.
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'vas@email.cz',
                    'autocomplete' => 'email',
                ],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'Jméno',
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'Jan',
                    'autocomplete' => 'given-name',
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Příjmení',
                'empty_data' => '',
                'attr' => [
                    'placeholder' => 'Novák',
                    'autocomplete' => 'family-name',
                ],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Telefon',
                'attr' => [
                    'placeholder' => '+420 123 456 789',
                    'autocomplete' => 'tel',
                ],
            ])
            ->add('birthDate', DateType::class, [
                'label' => 'Datum narození',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Vyžadováno pro účely nájemní smlouvy. Nájemce musí být starší 18 let.',
                'attr' => [
                    'autocomplete' => 'bday',
                    // Picker max = today − 18 years so the calendar can't even
                    // offer an under-18 date. Server-side {@see OrderFormData::validateBirthDate}
                    // is the source of truth (manual typing bypasses the picker).
                    'data-datepicker-max-date-value' => (new \DateTimeImmutable('today'))->modify('-18 years')->format('Y-m-d'),
                ],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'Heslo (nepovinné)',
                'required' => false,
                // PasswordType defaults to always_empty: true, which omits the value
                // from re-rendered HTML. Live UX morphs the form on every field
                // validation, so without this the user's typed password gets wiped
                // each time they blur another field.
                'always_empty' => false,
                'attr' => [
                    'placeholder' => 'Zadejte heslo pro vytvoření účtu',
                    'autocomplete' => 'new-password',
                ],
                'help' => 'Pokud zadáte heslo, bude vytvořen účet pro správu vašich objednávek.',
            ])
            ->add('invoiceToCompany', CheckboxType::class, [
                'label' => 'Fakturovat na společnost',
                'required' => false,
            ])
            ->add('companyId', TextType::class, [
                'label' => 'IČO',
                'required' => false,
                'attr' => [
                    'placeholder' => '12345678',
                    'maxlength' => 8,
                    // 8 digits → numeric keypad; no WHATWG autofill token exists for IČO.
                    'inputmode' => 'numeric',
                    'autocomplete' => 'off',
                ],
            ])
            ->add('companyName', TextType::class, [
                'label' => 'Název firmy',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Firma s.r.o.',
                    'autocomplete' => 'organization',
                ],
            ])
            ->add('companyVatId', TextType::class, [
                'label' => 'DIČ',
                'required' => false,
                'attr' => [
                    'placeholder' => 'CZ12345678',
                    // No autofill token for DIČ; suppress noise (keeps the text keyboard for "CZ…").
                    'autocomplete' => 'off',
                ],
            ])
            // The three address fields are mandatory for every order (enforced by
            // OrderFormData::validateAddress). They stay `required` (the default) so
            // the shared _address_override macro marks the "Adresa" search label as
            // required — the form is novalidate, so no browser validation kicks in.
            ->add('billingStreet', TextType::class, [
                'label' => 'Ulice a číslo popisné',
                'attr' => [
                    'placeholder' => 'Hlavní 123',
                ],
            ])
            ->add('billingCity', TextType::class, [
                'label' => 'Město',
                'attr' => [
                    'placeholder' => 'Praha',
                ],
            ])
            ->add('billingPostalCode', TextType::class, [
                'label' => 'PSČ',
                'attr' => [
                    'placeholder' => '110 00',
                    'maxlength' => 10,
                ],
            ])
            ->add('addressOverride', CheckboxType::class, [
                'label' => 'Adresa je správná, pokračovat',
                'required' => false,
            ])
            ->add('startDate', DateType::class, [
                'label' => 'Datum začátku',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => [
                    'data-datepicker-min-date-value' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
                ],
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Datum konce',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
                'attr' => [
                    'data-datepicker-min-date-value' => (new \DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d'),
                ],
            ])
            ->add('paymentFrequency', EnumType::class, self::paymentFrequencyOptions([
                PaymentFrequency::MONTHLY->label() => PaymentFrequency::MONTHLY,
                PaymentFrequency::YEARLY->label() => PaymentFrequency::YEARLY,
            ]))
            ->add('paymentMethod', EnumType::class, self::paymentMethodOptions(cardDisabled: false));

        // Live-component re-instantiates the form on every interaction, so PRE_SET_DATA
        // sees the latest startDate. Re-adding endDate bumps its datepicker min to
        // startDate + 7 days (the LIMITED rental minimum enforced in OrderFormData::validateDates).
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $data = $event->getData();
            if (!$data instanceof OrderFormData || null === $data->startDate) {
                return;
            }

            $event->getForm()->add('endDate', DateType::class, [
                'label' => 'Datum konce',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
                'attr' => [
                    'data-datepicker-min-date-value' => $data->startDate->modify('+7 days')->format('Y-m-d'),
                ],
            ]);

            self::reconfigurePaymentFields(
                $event->getForm(),
                $data->startDate,
                $data->endDate,
                PaymentFrequency::ONE_TIME === $data->paymentFrequency,
            );
        });

        // The Live Component hydrates the form model from session data (stale
        // during live editing) and then submits the client's current formValues,
        // so the date-dependent frequency choices must ALSO be rebuilt from the
        // raw submit — otherwise the radios would never react to date changes
        // and a legitimate 'one_time'/'yearly' submit would be rejected as an
        // unknown choice.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $raw = $event->getData();
            if (!is_array($raw)) {
                return;
            }

            $startDate = self::parseSubmittedDate($raw['startDate'] ?? null);
            if (null === $startDate) {
                return;
            }

            self::reconfigurePaymentFields(
                $event->getForm(),
                $startDate,
                self::parseSubmittedDate($raw['endDate'] ?? null),
                PaymentFrequency::ONE_TIME->value === ($raw['paymentFrequency'] ?? null),
            );
        });
    }

    /**
     * Rebuild the two payment fields for the chosen rental window (spec 078):
     * the frequency radios grow the Jednorázová option at ≥ 31 days and the
     * Roční option at ≥ 360 days, and while the upfront option is selected the
     * card radio renders disabled (bank-transfer only; the server-side
     * violation in OrderFormData::validatePaymentMethod is the backstop).
     *
     * @param FormInterface<mixed> $form
     */
    private static function reconfigurePaymentFields(
        FormInterface $form,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        bool $upfrontSelected,
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
        $form->add('paymentMethod', EnumType::class, self::paymentMethodOptions(cardDisabled: $upfrontSelected));
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
            'required' => false,
            'placeholder' => false,
            'choices' => $choices,
            'help' => 'Roční platba = jedna platba předem na celý rok se slevou 10 %. Jednorázová platba = celý pronájem předem jedním převodem. Obě lze platit pouze bankovním převodem.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paymentMethodOptions(bool $cardDisabled): array
    {
        return [
            'class' => PaymentMethod::class,
            'label' => 'Způsob platby',
            'expanded' => true,
            'placeholder' => false,
            'choices' => [
                'Platba kartou (GoPay)' => PaymentMethod::GOPAY,
                'Bankovní převod' => PaymentMethod::BANK_TRANSFER,
            ],
            // Progressive enhancement only — the Live Component keeps previously
            // synced values, so the GOPAY+ONE_TIME server violation stays the gate.
            'choice_attr' => static fn (PaymentMethod $method): array => $cardDisabled && PaymentMethod::GOPAY === $method
                ? ['disabled' => 'disabled']
                : [],
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
            'data_class' => OrderFormData::class,
            'csrf_protection' => false,
        ]);
    }
}
