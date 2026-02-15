<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ProfileFormData>
 */
final class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('firstName', TextType::class, [
            'label' => 'Jméno',
            'attr' => [
                'placeholder' => 'Jan',
            ],
        ]);

        $builder->add('lastName', TextType::class, [
            'label' => 'Příjmení',
            'attr' => [
                'placeholder' => 'Novák',
            ],
        ]);

        $builder->add('phone', TelType::class, [
            'label' => 'Telefon',
            'required' => false,
            'attr' => [
                'placeholder' => '+420 123 456 789',
            ],
        ]);

        $builder->add('bankAccountNumber', TextType::class, [
            'label' => 'Číslo účtu',
            'required' => false,
            'attr' => [
                'placeholder' => '123456-1234567890',
            ],
        ]);

        $builder->add('bankCode', TextType::class, [
            'label' => 'Kód banky',
            'required' => false,
            'attr' => [
                'placeholder' => '0100',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileFormData::class,
        ]);
    }
}
