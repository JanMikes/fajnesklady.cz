<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @extends AbstractType<LandlordRegistrationFormData>
 */
final class LandlordRegistrationFormType extends AbstractType
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'attr' => [
                    'placeholder' => 'vas@email.cz',
                    'autocomplete' => 'email',
                ],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'Jméno',
                'attr' => [
                    'placeholder' => 'Jan',
                    'autocomplete' => 'given-name',
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Příjmení',
                'attr' => [
                    'placeholder' => 'Novák',
                    'autocomplete' => 'family-name',
                ],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Telefon',
                'required' => false,
                'attr' => [
                    'placeholder' => '+420 123 456 789',
                    'autocomplete' => 'tel',
                ],
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'first_options' => [
                    'label' => 'Heslo',
                    'attr' => [
                        'placeholder' => 'Zadejte heslo',
                        'autocomplete' => 'new-password',
                    ],
                ],
                'second_options' => [
                    'label' => 'Heslo znovu',
                    'attr' => [
                        'placeholder' => 'Zopakujte heslo',
                        'autocomplete' => 'new-password',
                    ],
                ],
                'invalid_message' => 'Hesla se musí shodovat.',
            ])
            ->add('companyId', TextType::class, [
                'label' => 'IČO',
                'attr' => [
                    'placeholder' => '12345678',
                    'maxlength' => 8,
                ],
            ])
            ->add('companyName', TextType::class, [
                'label' => 'Název firmy',
                'attr' => [
                    'placeholder' => 'Firma s.r.o.',
                ],
            ])
            ->add('companyVatId', TextType::class, [
                'label' => 'DIČ',
                'required' => false,
                'attr' => [
                    'placeholder' => 'CZ12345678',
                ],
            ])
            ->add('billingStreet', TextType::class, [
                'label' => 'Ulice a číslo popisné',
                'attr' => [
                    'placeholder' => 'Hlavní 123',
                ],
            ])
            ->add('billingCity', TextType::class, [
                'label' => 'Město',
                'attr' => [
                    'placeholder' => 'Praha',
                ],
            ])
            ->add('billingPostalCode', TextType::class, [
                'label' => 'PSČ',
                'attr' => [
                    'placeholder' => '110 00',
                    'maxlength' => 10,
                ],
            ])
            ->add('bankAccountNumber', TextType::class, [
                'label' => 'Číslo účtu',
                'attr' => [
                    'placeholder' => '123456-1234567890',
                ],
            ])
            ->add('bankCode', TextType::class, [
                'label' => 'Kód banky',
                'attr' => [
                    'placeholder' => '0100',
                    'maxlength' => 4,
                ],
            ])
            ->add('agreeTerms', CheckboxType::class, [
                'label' => sprintf('Souhlasím s <a href="%s" target="_blank" class="link">obchodními podmínkami</a>', $this->urlGenerator->generate('public_terms_and_conditions')),
                'label_html' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LandlordRegistrationFormData::class,
        ]);
    }
}
