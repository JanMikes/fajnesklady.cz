<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<LandlordHandoverFormData>
 */
final class LandlordHandoverFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('comment', TextareaType::class, [
                'label' => 'Komentář',
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'Popište stav skladu při převzetí...',
                ],
            ])
            ->add('newLockCode', TextType::class, [
                'label' => 'Nový kód zámku',
                'required' => false,
                'attr' => [
                    'placeholder' => 'Zadejte kód zámku pro dalšího nájemce',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LandlordHandoverFormData::class,
        ]);
    }
}
