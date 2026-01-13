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
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileFormData::class,
        ]);
    }
}
