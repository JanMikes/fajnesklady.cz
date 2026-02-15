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
            'label' => 'E-mailová adresa',
            'attr' => [
                'placeholder' => 'vas@email.cz',
            ],
        ]);

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

        $builder->add('password', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'Heslo',
                'attr' => [
                    'placeholder' => 'Zadejte heslo',
    
                ],
            ],
            'second_options' => [
                'label' => 'Heslo znovu',
                'attr' => [
                    'placeholder' => 'Zopakujte heslo',
    
                ],
            ],
            'invalid_message' => 'Hesla se musí shodovat.',
        ]);

        $builder->add('agreeTerms', CheckboxType::class, [
            'label' => 'Souhlasím s obchodními podmínkami',


        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrationFormData::class,
        ]);
    }
}
