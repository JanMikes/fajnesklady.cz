<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PlatformSettingsFormData>
 */
final class PlatformSettingsFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('bankTransferSurchargeInCzk', NumberType::class, [
                'label' => 'Příplatek za bankovní převod (Kč / měsíc)',
                'scale' => 0,
                'attr' => ['placeholder' => '100'],
                'help' => 'Příplatek se připočítá k měsíční sazbě u nových objednávek s platbou bankovním převodem.',
            ])
            ->add('overdueTerminationDays', IntegerType::class, [
                'label' => 'Automatické ukončení smlouvy po splatnosti (dny)',
                'attr' => ['placeholder' => '7'],
                'help' => 'Smlouva je automaticky ukončena bez výpovědní doby, pokud platba není přijata do tohoto počtu dní po splatnosti (VOP čl. XI). Minimum je 7 dní.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlatformSettingsFormData::class,
            'csrf_protection' => false,
        ]);
    }
}
