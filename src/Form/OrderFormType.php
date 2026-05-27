<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\BillingMode;
use App\Enum\ExpectedDuration;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Enum\RentalType;
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
                ],
            ])
            ->add('companyName', TextType::class, [
                'label' => 'Název firmy',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Firma s.r.o.',
                ],
            ])
            ->add('companyVatId', TextType::class, [
                'label' => 'DIČ',
                'required' => false,
                'attr' => [
                    'placeholder' => 'CZ12345678',
                ],
            ])
            ->add('billingStreet', TextType::class, [
                'label' => 'Ulice a číslo popisné',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Hlavní 123',
                ],
            ])
            ->add('billingCity', TextType::class, [
                'label' => 'Město',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Praha',
                ],
            ])
            ->add('billingPostalCode', TextType::class, [
                'label' => 'PSČ',
                'required' => false,
                'attr' => [
                    'placeholder' => '110 00',
                    'maxlength' => 10,
                ],
            ])
            ->add('addressOverride', CheckboxType::class, [
                'label' => 'Adresa je správná, pokračovat',
                'required' => false,
            ])
            ->add('rentalType', EnumType::class, [
                'class' => RentalType::class,
                'label' => 'Typ pronájmu',
                'expanded' => true,
                'choice_label' => fn (RentalType $type) => match ($type) {
                    RentalType::LIMITED => 'Na dobu určitou',
                    RentalType::UNLIMITED => 'Na dobu neurčitou (automaticky prodlužováno)',
                },
            ])
            ->add('expectedDuration', EnumType::class, [
                'class' => ExpectedDuration::class,
                'label' => 'Předpokládaná doba pronájmu',
                'label_attr' => ['class' => 'required'],
                'expanded' => true,
                // Live UX re-submits the form on every per-field blur with the
                // radio potentially empty; server-side validateExpectedDuration
                // is the source of truth when UNLIMITED is selected.
                'required' => false,
                'placeholder' => false,
                'choice_label' => fn (ExpectedDuration $d) => $d->label(),
                'help' => 'Informativní údaj pro provozovatele, nemá vliv na cenu ani podmínky pronájmu.',
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
            ->add('billingMode', EnumType::class, [
                'class' => BillingMode::class,
                'label' => 'Způsob platby',
                'expanded' => true,
                'required' => false,
                // required: false is needed so live per-field validation doesn't fail when
                // the form is re-submitted with an empty radio; without placeholder: false
                // Symfony would render a stray empty "None" radio above the real choices.
                'placeholder' => false,
                'choices' => [
                    'Automatická platba kartou' => BillingMode::AUTO_RECURRING,
                    'Ručně schvalovaná platba (výzva e-mailem)' => BillingMode::MANUAL_RECURRING,
                ],
                'help' => 'Při ručně schvalované platbě Vám 7 dní před každou platbou pošleme e-mail s odkazem k zaplacení. Údaje o platební kartě se neukládají.',
            ])
            ->add('paymentFrequency', EnumType::class, [
                'class' => PaymentFrequency::class,
                'label' => 'Frekvence platby',
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'choices' => [
                    PaymentFrequency::MONTHLY->label() => PaymentFrequency::MONTHLY,
                    PaymentFrequency::YEARLY->label() => PaymentFrequency::YEARLY,
                ],
                'help' => 'Roční platba znamená jednu platbu předem na celý rok. Karta se neukládá — další platbu obdržíte e-mailem před vypršením roku.',
            ])
            ->add('paymentMethod', EnumType::class, [
                'class' => PaymentMethod::class,
                'label' => 'Způsob platby',
                'expanded' => true,
                'placeholder' => false,
                'choices' => [
                    'Platba kartou (GoPay)' => PaymentMethod::GOPAY,
                    'Bankovní převod' => PaymentMethod::BANK_TRANSFER,
                ],
            ]);

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
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OrderFormData::class,
        ]);
    }
}
