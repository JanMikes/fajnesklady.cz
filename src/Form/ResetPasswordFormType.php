<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ResetPasswordFormData>
 */
final class ResetPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('newPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => [
                'label' => 'Nové heslo',
                'attr' => [
                    'placeholder' => 'Zadejte nové heslo',
                    'class' => 'input input-bordered w-full',
                ],
            ],
            'second_options' => [
                'label' => 'Potvrzení hesla',
                'attr' => [
                    'placeholder' => 'Zopakujte nové heslo',
                    'class' => 'input input-bordered w-full',
                ],
            ],
            'invalid_message' => 'Hesla se musí shodovat.',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ResetPasswordFormData::class,
        ]);
    }
}
