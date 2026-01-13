<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\UserRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AdminUserFormData>
 */
final class AdminUserFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('firstName', TextType::class, [
            'label' => 'Jméno',
            'attr' => [
                'placeholder' => 'Jan',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('lastName', TextType::class, [
            'label' => 'Příjmení',
            'attr' => [
                'placeholder' => 'Novák',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('phone', TelType::class, [
            'label' => 'Telefon',
            'required' => false,
            'attr' => [
                'placeholder' => '+420 123 456 789',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('companyName', TextType::class, [
            'label' => 'Název firmy',
            'required' => false,
            'attr' => [
                'placeholder' => 'Firma s.r.o.',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('companyId', TextType::class, [
            'label' => 'IČO',
            'required' => false,
            'attr' => [
                'placeholder' => '12345678',
                'class' => 'input input-bordered w-full',
                'maxlength' => 8,
            ],
        ]);

        $builder->add('companyVatId', TextType::class, [
            'label' => 'DIČ',
            'required' => false,
            'attr' => [
                'placeholder' => 'CZ12345678',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('billingStreet', TextType::class, [
            'label' => 'Ulice a číslo popisné',
            'required' => false,
            'attr' => [
                'placeholder' => 'Hlavní 123',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('billingCity', TextType::class, [
            'label' => 'Město',
            'required' => false,
            'attr' => [
                'placeholder' => 'Praha',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('billingPostalCode', TextType::class, [
            'label' => 'PSČ',
            'required' => false,
            'attr' => [
                'placeholder' => '110 00',
                'class' => 'input input-bordered w-full',
                'maxlength' => 10,
            ],
        ]);

        $builder->add('role', EnumType::class, [
            'class' => UserRole::class,
            'label' => 'Role',
            'expanded' => true,
            'choice_label' => static fn (UserRole $role): string => $role->label(),
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminUserFormData::class,
        ]);
    }
}
