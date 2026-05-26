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
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AdminOnboardingFormData>
 */
final class AdminOnboardingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'attr' => ['placeholder' => 'zakaznik@example.com'],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'Jméno',
                'attr' => ['placeholder' => 'Jan'],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Příjmení',
                'attr' => ['placeholder' => 'Novák'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Telefon',
                'required' => false,
                'attr' => ['placeholder' => '+420 123 456 789'],
            ])
            ->add('birthDate', DateType::class, [
                'label' => 'Datum narození',
                'required' => false,
                'widget' => 'single_text',
                'attr' => [
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
                'attr' => ['placeholder' => 'Firma s.r.o.'],
            ])
            ->add('companyId', TextType::class, [
                'label' => 'IČO',
                'required' => false,
                'attr' => ['placeholder' => '12345678', 'maxlength' => 8],
            ])
            ->add('companyVatId', TextType::class, [
                'label' => 'DIČ',
                'required' => false,
                'attr' => ['placeholder' => 'CZ12345678'],
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
            ->add('rentalType', EnumType::class, [
                'class' => RentalType::class,
                'label' => 'Typ pronájmu',
                'expanded' => true,
                'choice_label' => static fn (RentalType $type): string => match ($type) {
                    RentalType::LIMITED => 'Doba určitá',
                    RentalType::UNLIMITED => 'Doba neurčitá',
                },
            ])
            ->add('expectedDuration', EnumType::class, [
                'class' => ExpectedDuration::class,
                'label' => 'Předpokládaná doba pronájmu',
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'choice_label' => static fn (ExpectedDuration $d): string => $d->label(),
                'help' => 'Jen pro pronájem na dobu neurčitou. Informativní údaj pro provozovatele.',
            ])
            ->add('startDate', DateType::class, [
                'label' => 'Datum začátku',
                'widget' => 'single_text',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Datum konce',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('paymentMethod', EnumType::class, [
                'class' => PaymentMethod::class,
                'label' => 'Způsob platby',
                'expanded' => true,
                'choice_label' => static fn (PaymentMethod $method): string => match ($method) {
                    PaymentMethod::EXTERNAL => 'Externí platba (hotovost, jiné)',
                    PaymentMethod::GOPAY => 'GoPay (zákazník nastaví při podpisu)',
                    PaymentMethod::BANK_TRANSFER => 'Bankovní převod (zákazník platí převodem)',
                },
            ])
            ->add('billingMode', EnumType::class, [
                'class' => BillingMode::class,
                'label' => 'Způsob následných plateb',
                'expanded' => true,
                'choices' => [
                    'Automatická (uloží se karta, strhává se sama)' => BillingMode::AUTO_RECURRING,
                    'Ručně (každý měsíc dostane e-mail s platebním odkazem)' => BillingMode::MANUAL_RECURRING,
                ],
                'help' => 'Pro pronájem na dobu neurčitou je dostupná pouze automatická. Roční platba je vždy ruční.',
            ])
            ->add('paymentFrequency', EnumType::class, [
                'class' => PaymentFrequency::class,
                'label' => 'Frekvence platby',
                'expanded' => true,
                'choices' => [
                    PaymentFrequency::MONTHLY->label() => PaymentFrequency::MONTHLY,
                    PaymentFrequency::YEARLY->label() => PaymentFrequency::YEARLY,
                ],
                'help' => 'Roční platba je dostupná, pokud má typ skladu nastavenou roční sazbu a doba pronájmu je 12+ měsíců (nebo neurčitá). Vždy se účtuje ručně.',
            ])
            ->add('monthlyPriceMode', ChoiceType::class, [
                'label' => 'Cenový model',
                'expanded' => true,
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
                'attr' => ['placeholder' => '1500.00'],
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
                'attr' => ['placeholder' => 'Ponechte prázdné pro automatické vygenerování'],
                'help' => 'Pouze pro bankovní převod. Číselný, max 10 číslic.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminOnboardingFormData::class,
        ]);
    }
}
