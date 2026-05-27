<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\PlaceType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PlaceFormData>
 */
class PlaceFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('type', EnumType::class, [
            'class' => PlaceType::class,
            'label' => 'Typ místa',
            'choice_label' => fn (PlaceType $type) => $type->label(),
        ]);

        $builder->add('name', TextType::class, [
            'label' => 'Název',
            'empty_data' => '',
            'attr' => [
                'placeholder' => 'Název místa',
            ],
        ]);

        $builder->add('useMapLocation', CheckboxType::class, [
            'required' => false,
            'label' => 'Místo nemá adresu, vybrat na mapě',
        ]);

        $builder->add('address', TextType::class, [
            'label' => 'Adresa',
            'required' => false,
            'attr' => [
                'placeholder' => 'Ulice a číslo popisné',
            ],
        ]);

        $builder->add('city', TextType::class, [
            'label' => 'Město',
            'empty_data' => '',
            'attr' => [
                'placeholder' => 'Praha',
            ],
        ]);

        $builder->add('postalCode', TextType::class, [
            'label' => 'PSČ',
            'empty_data' => '',
            'attr' => [
                'placeholder' => '110 00',
            ],
        ]);

        $builder->add('latitude', TextType::class, [
            'label' => 'Zeměpisná šířka',
            'required' => false,
            'attr' => [
                'placeholder' => '49.7437572',
                'inputmode' => 'decimal',
            ],
        ]);

        $builder->add('longitude', TextType::class, [
            'label' => 'Zeměpisná délka',
            'required' => false,
            'attr' => [
                'placeholder' => '13.3799330',
                'inputmode' => 'decimal',
            ],
        ]);

        $builder->add('description', TextareaType::class, [
            'label' => 'Popis',
            'required' => false,
            'attr' => [
                'placeholder' => 'Volitelný popis místa',

                'rows' => 4,
            ],
        ]);

        $builder->add('orderExpirationDays', IntegerType::class, [
            'label' => 'Doba platnosti objednávky (dny)',
            'help' => 'Po této době nezaplacená objednávka automaticky vyprší. Výchozí 3 dny.',
            'attr' => [
                'min' => 1,
                'max' => 30,
                'inputmode' => 'numeric',
            ],
        ]);

        $builder->add('mapImage', FileType::class, [
            'label' => 'Mapa skladu',
            'required' => false,
            'attr' => [
                'accept' => 'image/jpeg,image/png,image/webp',
            ],
            'help' => 'Obrázek mapy skladu (JPEG, PNG, WebP, max 5 MB)',
        ]);

        $builder->add('operatingRulesDocument', FileType::class, [
            'label' => 'Provozní řád',
            'required' => false,
            'attr' => [
                'accept' => 'application/pdf,.pdf,.docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
            'help' => 'Dokument provozního řádu (PDF nebo DOCX, max 10 MB)',
        ]);

        if ($options['is_admin']) {
            $builder->add('instructionsDocument', FileType::class, [
                'label' => 'Návod pro zákazníky',
                'required' => false,
                'attr' => [
                    'accept' => 'application/pdf,.pdf,.docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                ],
                'help' => 'Praktický návod pro zákazníky (PDF nebo DOCX, max 10 MB). Po platbě je přiložen k potvrzovacímu e-mailu.',
            ]);

            $builder->add('manualBillingOffsetInitial', IntegerType::class, [
                'label' => 'Úvodní výzva k platbě (záporné číslo = X dní před splatností)',
                'help' => 'Výchozí: -7',
                'attr' => ['inputmode' => 'numeric'],
            ]);
            $builder->add('manualBillingOffsetReminder', IntegerType::class, [
                'label' => 'Připomenutí',
                'help' => 'Výchozí: -2',
                'attr' => ['inputmode' => 'numeric'],
            ]);
            $builder->add('manualBillingOffsetFinalDue', IntegerType::class, [
                'label' => 'V den splatnosti',
                'help' => 'Výchozí: 0',
                'attr' => ['inputmode' => 'numeric'],
            ]);
            $builder->add('manualBillingOffsetOverdueFirst', IntegerType::class, [
                'label' => 'První upomínka po splatnosti (kladné číslo)',
                'help' => 'Výchozí: 3',
                'attr' => ['inputmode' => 'numeric'],
            ]);
            $builder->add('manualBillingOffsetOverdueFinal', IntegerType::class, [
                'label' => 'Poslední upomínka po splatnosti',
                'help' => 'Výchozí: 7',
                'attr' => ['inputmode' => 'numeric'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PlaceFormData::class,
            'is_admin' => false,
        ]);
        $resolver->setAllowedTypes('is_admin', 'bool');
    }
}
