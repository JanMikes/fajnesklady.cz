<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\RentalType;
use App\Service\Form\StorageChoiceBuilder;
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
 * @extends AbstractType<AdminMigrateCustomerFormData>
 */
final class AdminMigrateCustomerFormType extends AbstractType
{
    public function __construct(
        private readonly StorageChoiceBuilder $storageChoiceBuilder,
    ) {
    }

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
                    'data-datepicker-max-date-value' => (new \DateTimeImmutable('today'))->format('Y-m-d'),
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
            ->add('storageId', ChoiceType::class, [
                'label' => 'Skladová jednotka',
                'choices' => $this->storageChoiceBuilder->buildAvailableGroupedChoices(),
                'placeholder' => '-- Vyberte skladovou jednotku --',
                'attr' => ['data-controller' => 'tom-select'],
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
            ->add('startDate', DateType::class, [
                'label' => 'Datum začátku',
                'widget' => 'single_text',
            ])
            ->add('endDate', DateType::class, [
                'label' => 'Datum konce',
                'required' => false,
                'widget' => 'single_text',
            ])
            ->add('contractDocument', FileType::class, [
                'label' => 'Podepsaná smlouva (PDF, JPEG, PNG)',
            ])
            ->add('totalPriceInCzk', NumberType::class, [
                'label' => 'Zaplacená částka externě (Kč)',
                'scale' => 2,
                'attr' => ['placeholder' => '18000.00'],
                'help' => 'Celková částka, kterou zákazník zaplatil mimo GoPay (např. v hotovosti nebo převodem).',
            ])
            ->add('paidAt', DateType::class, [
                'label' => 'Datum platby',
                'widget' => 'single_text',
            ])
            ->add('paidThroughDate', DateType::class, [
                'label' => 'Předplaceno do',
                'widget' => 'single_text',
                'help' => 'Datum, do kterého externí platba pokrývá pronájem. Pro doby určité přednastavte datum konce smlouvy.',
            ])
            ->add('monthlyPriceMode', ChoiceType::class, [
                'label' => 'Cenový model pro budoucí měsíční platby',
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
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminMigrateCustomerFormData::class,
        ]);
    }
}
