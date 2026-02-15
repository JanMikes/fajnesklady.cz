<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<BillingInfoFormData>
 */
final class BillingInfoFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('companyId', TextType::class, [
            'label' => 'IČO',
            'required' => false,
            'attr' => [
                'placeholder' => '12345678',

                'maxlength' => 8,
            ],
        ]);

        $builder->add('companyName', TextType::class, [
            'label' => 'Název firmy',
            'required' => false,
            'attr' => [
                'placeholder' => 'Firma s.r.o.',
            ],
        ]);

        $builder->add('companyVatId', TextType::class, [
            'label' => 'DIČ',
            'required' => false,
            'attr' => [
                'placeholder' => 'CZ12345678',
            ],
        ]);

        $builder->add('billingStreet', TextType::class, [
            'label' => 'Ulice a číslo popisné',
            'required' => false,
            'attr' => [
                'placeholder' => 'Hlavní 123',
            ],
        ]);

        $builder->add('billingCity', TextType::class, [
            'label' => 'Město',
            'required' => false,
            'attr' => [
                'placeholder' => 'Praha',
            ],
        ]);

        $builder->add('billingPostalCode', TextType::class, [
            'label' => 'PSČ',
            'required' => false,
            'attr' => [
                'placeholder' => '110 00',

                'maxlength' => 10,
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => BillingInfoFormData::class,
        ]);
    }
}
