<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\UserRole;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
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

        $builder->add('companyName', TextType::class, [
            'label' => 'Název firmy',
            'required' => false,
            'attr' => [
                'placeholder' => 'Firma s.r.o.',
            ],
        ]);

        $builder->add('companyId', TextType::class, [
            'label' => 'IČO',
            'required' => false,
            'attr' => [
                'placeholder' => '12345678',

                'maxlength' => 8,
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

        $builder->add('role', EnumType::class, [
            'class' => UserRole::class,
            'label' => 'Role',
            'expanded' => true,
            'choice_label' => static fn (UserRole $role): string => $role->label(),
        ]);

        // Self-billing settings (for landlords)
        $builder->add('commissionRate', NumberType::class, [
            'label' => 'Provize pro pronajimatele (%)',
            'required' => false,
            'scale' => 0,
            'attr' => [
                'placeholder' => 'Vychozi 90%',

                'min' => 0,
                'max' => 100,
            ],
            'help' => 'Procento z platby, ktere obdrzi pronajimatel. Nechte prazdne pro 90%.',
        ]);

        $builder->add('selfBillingPrefix', TextType::class, [
            'label' => 'Prefix samofakturace',
            'required' => false,
            'attr' => [
                'placeholder' => 'napr. P001',

                'maxlength' => 10,
            ],
            'help' => 'Prefix pro samofakturacni doklady (napr. P001). Povinne pro samofakturaci.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AdminUserFormData::class,
        ]);
    }
}
