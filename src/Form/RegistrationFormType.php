<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<RegistrationFormData>
 */
class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('email', EmailType::class, [
            'label' => 'Email Address',
            'attr' => [
                'placeholder' => 'your@email.com',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('firstName', TextType::class, [
            'label' => 'First Name',
            'attr' => [
                'placeholder' => 'Jan',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('lastName', TextType::class, [
            'label' => 'Last Name',
            'attr' => [
                'placeholder' => 'Novak',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('password', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'Password',
                'attr' => [
                    'placeholder' => 'Enter password',
                    'class' => 'input input-bordered w-full',
                ],
            ],
            'second_options' => [
                'label' => 'Repeat Password',
                'attr' => [
                    'placeholder' => 'Repeat password',
                    'class' => 'input input-bordered w-full',
                ],
            ],
            'invalid_message' => 'The password fields must match.',
        ]);

        $builder->add('agreeTerms', CheckboxType::class, [
            'label' => 'I agree to the terms and conditions',
            'attr' => [
                'class' => 'checkbox checkbox-primary',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrationFormData::class,
        ]);
    }
}
