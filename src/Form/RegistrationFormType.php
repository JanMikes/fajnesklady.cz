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
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @extends AbstractType<RegistrationFormData>
 */
class RegistrationFormType extends AbstractType
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

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

        $builder->add('isCompany', CheckboxType::class, [
            'label' => 'Registrovat jako firma',
            'required' => false,
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

        $termsUrl = $this->urlGenerator->generate('public_terms_and_conditions');
        $builder->add('agreeTerms', CheckboxType::class, [
            'label' => sprintf('Souhlasím s <a href="%s" target="_blank" class="link">obchodními podmínkami</a>', $termsUrl),
            'label_html' => true,
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrationFormData::class,
            'validation_groups' => static function (FormInterface $form): array {
                /** @var RegistrationFormData $data */
                $data = $form->getData();

                $groups = ['Default'];
                if ($data->isCompany) {
                    $groups[] = 'company';
                }

                return $groups;
            },
        ]);
    }
}
