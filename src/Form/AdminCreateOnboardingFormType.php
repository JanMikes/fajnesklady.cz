<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\PaymentMethod;
use App\Enum\RentalType;
use App\Repository\StorageRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AdminCreateOnboardingFormData>
 */
final class AdminCreateOnboardingFormType extends AbstractType
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
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
                'choices' => $this->getStorageChoices(),
                'placeholder' => '-- Vyberte skladovou jednotku --',
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
            ->add('paymentMethod', EnumType::class, [
                'class' => PaymentMethod::class,
                'label' => 'Způsob platby',
                'expanded' => true,
                'choice_label' => static fn (PaymentMethod $method): string => match ($method) {
                    PaymentMethod::EXTERNAL => 'Externí platba (bankovní převod, hotovost)',
                    PaymentMethod::GOPAY => 'GoPay (zákazník nastaví při podpisu)',
                },
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminCreateOnboardingFormData::class,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function getStorageChoices(): array
    {
        $storages = $this->storageRepository->findAllAvailable();

        $choices = [];
        foreach ($storages as $storage) {
            $label = sprintf(
                '%s - %s (%s)',
                $storage->place->name,
                $storage->storageType->name,
                $storage->number,
            );
            $choices[$label] = $storage->id->toRfc4122();
        }

        return $choices;
    }
}
