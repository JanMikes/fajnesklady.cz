<?php

declare(strict_types=1);

namespace App\Form;

use App\Repository\PlaceRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @extends AbstractType<StorageTypeFormData>
 */
class StorageTypeFormType extends AbstractType
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Nazev',
            'attr' => [
                'placeholder' => 'Nazev typu skladu',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        // Inner dimensions (required)
        $builder->add('innerWidth', IntegerType::class, [
            'label' => 'Vnitrni sirka (cm)',
            'attr' => [
                'placeholder' => '200',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('innerHeight', IntegerType::class, [
            'label' => 'Vnitrni vyska (cm)',
            'attr' => [
                'placeholder' => '250',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('innerLength', IntegerType::class, [
            'label' => 'Vnitrni delka (cm)',
            'attr' => [
                'placeholder' => '300',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        // Outer dimensions (optional)
        $builder->add('outerWidth', IntegerType::class, [
            'label' => 'Vnejsi sirka (cm)',
            'required' => false,
            'attr' => [
                'placeholder' => '210',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('outerHeight', IntegerType::class, [
            'label' => 'Vnejsi vyska (cm)',
            'required' => false,
            'attr' => [
                'placeholder' => '260',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('outerLength', IntegerType::class, [
            'label' => 'Vnejsi delka (cm)',
            'required' => false,
            'attr' => [
                'placeholder' => '310',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('pricePerWeek', NumberType::class, [
            'label' => 'Cena za tyden (CZK)',
            'scale' => 2,
            'attr' => [
                'placeholder' => '500.00',
                'class' => 'input input-bordered w-full',
                'step' => '0.01',
            ],
        ]);

        $builder->add('pricePerMonth', NumberType::class, [
            'label' => 'Cena za mesic (CZK)',
            'scale' => 2,
            'attr' => [
                'placeholder' => '1500.00',
                'class' => 'input input-bordered w-full',
                'step' => '0.01',
            ],
        ]);

        $builder->add('description', TextareaType::class, [
            'label' => 'Popis',
            'required' => false,
            'attr' => [
                'placeholder' => 'Volitelny popis typu skladu',
                'class' => 'textarea textarea-bordered w-full',
                'rows' => 3,
            ],
        ]);

        $builder->add('photos', FileType::class, [
            'label' => 'Fotografie',
            'required' => false,
            'multiple' => true,
            'attr' => [
                'accept' => 'image/jpeg,image/png,image/webp',
                'class' => 'file-input file-input-bordered w-full',
            ],
            'help' => 'Nahrajte fotografie skladu (JPEG, PNG, WebP, max 5 MB kazda)',
        ]);

        // Only show place selector for admins
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            $builder->add('placeId', ChoiceType::class, [
                'label' => 'Misto',
                'choices' => $this->getPlaceChoices(),
                'attr' => [
                    'class' => 'select select-bordered w-full',
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StorageTypeFormData::class,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function getPlaceChoices(): array
    {
        $places = $this->placeRepository->findAll();
        $choices = [];

        foreach ($places as $place) {
            $choices[$place->name.' ('.$place->address.')'] = $place->id->toRfc4122();
        }

        return $choices;
    }
}
