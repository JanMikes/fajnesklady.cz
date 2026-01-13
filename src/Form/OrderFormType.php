<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<OrderFormData>
 */
final class OrderFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'attr' => [
                    'placeholder' => 'vas@email.cz',
                    'autocomplete' => 'email',
                ],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'Jméno',
                'attr' => [
                    'placeholder' => 'Jan',
                    'autocomplete' => 'given-name',
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Příjmení',
                'attr' => [
                    'placeholder' => 'Novák',
                    'autocomplete' => 'family-name',
                ],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Telefon',
                'required' => false,
                'attr' => [
                    'placeholder' => '+420 123 456 789',
                    'autocomplete' => 'tel',
                ],
            ])
            ->add('companyId', TextType::class, [
                'label' => 'IČO',
                'attr' => [
                    'placeholder' => '12345678',
                    'maxlength' => 8,
                ],
            ])
            ->add('companyName', TextType::class, [
                'label' => 'Název firmy',
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
            ->add('rentalType', EnumType::class, [
                'class' => RentalType::class,
                'label' => 'Typ pronájmu',
                'expanded' => true,
                'choice_label' => fn (RentalType $type) => match ($type) {
                    RentalType::LIMITED => 'Na dobu určitou',
                    RentalType::UNLIMITED => 'Na dobu neurčitou',
                },
            ])
            ->add('paymentFrequency', EnumType::class, [
                'class' => PaymentFrequency::class,
                'label' => 'Frekvence plateb',
                'required' => false,
                'placeholder' => false,
                'choice_label' => fn (PaymentFrequency $freq) => match ($freq) {
                    PaymentFrequency::MONTHLY => 'Měsíčně',
                    PaymentFrequency::YEARLY => 'Ročně',
                },
            ])
            ->add('startDate', DateType::class, [
                'label' => 'Datum začátku',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => [
                    'data-controller' => 'datepicker',
                    'data-datepicker-min-date-value' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
                ],
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Datum konce',
                'widget' => 'single_text',
                'required' => false,
                'input' => 'datetime_immutable',
                'attr' => [
                    'data-controller' => 'datepicker',
                    'data-datepicker-min-date-value' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d'),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OrderFormData::class,
        ]);
    }
}
