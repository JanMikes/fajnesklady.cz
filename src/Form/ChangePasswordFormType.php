<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ChangePasswordFormData>
 */
final class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('currentPassword', PasswordType::class, [
            'label' => 'Aktuální heslo',
            'attr' => [
                'placeholder' => 'Zadejte aktuální heslo',

                'autocomplete' => 'current-password',
            ],
        ]);

        $builder->add('newPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'Nové heslo',
                'attr' => [
                    'placeholder' => 'Zadejte nové heslo',
    
                    'autocomplete' => 'new-password',
                ],
            ],
            'second_options' => [
                'label' => 'Potvrzení nového hesla',
                'attr' => [
                    'placeholder' => 'Zopakujte nové heslo',
    
                    'autocomplete' => 'new-password',
                ],
            ],
            'invalid_message' => 'Hesla se neshodují.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ChangePasswordFormData::class,
        ]);
    }
}
