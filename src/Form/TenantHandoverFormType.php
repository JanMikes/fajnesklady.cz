<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<TenantHandoverFormData>
 */
final class TenantHandoverFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('comment', TextareaType::class, [
                'label' => 'Komentář',
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'Popište stav skladu při předání...',
                ],
            ])
            ->add('confirmed', CheckboxType::class, [
                'label' => 'Potvrzuji, že jsem sklad vyklidil/a a předávám ho zpět.',
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => TenantHandoverFormData::class,
        ]);
    }
}
