<?php

declare(strict_types=1);

namespace App\User\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\IsTrue;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PasswordStrength;

/**
 * @extends AbstractType<array<string, mixed>>
 */
class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email Address',
                'constraints' => [
                    new NotBlank(message: 'Please enter your email address'),
                    new Email(message: 'Please enter a valid email address'),
                ],
                'attr' => [
                    'placeholder' => 'your@email.com',
                    'class' => 'input input-bordered w-full',
                ],
            ])
            ->add('name', TextType::class, [
                'label' => 'Full Name',
                'constraints' => [
                    new NotBlank(message: 'Please enter your name'),
                    new Length(
                        max: 255,
                        maxMessage: 'Your name cannot be longer than {{ limit }} characters',
                    ),
                ],
                'attr' => [
                    'placeholder' => 'John Doe',
                    'class' => 'input input-bordered w-full',
                ],
            ])
            ->add('password', RepeatedType::class, [
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
                'constraints' => [
                    new NotBlank(message: 'Please enter a password'),
                    new Length(
                        min: 8,
                        minMessage: 'Your password should be at least {{ limit }} characters',
                    ),
                    new PasswordStrength(
                        minScore: PasswordStrength::STRENGTH_MEDIUM,
                        message: 'Your password is too weak. Please use a stronger password.',
                    ),
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'label' => 'I agree to the terms and conditions',
                'mapped' => false,
                'constraints' => [
                    new IsTrue(message: 'You must agree to the terms and conditions.'),
                ],
                'attr' => [
                    'class' => 'checkbox checkbox-primary',
                ],
            ])
        ;
    }
}
