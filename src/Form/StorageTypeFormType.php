<?php

declare(strict_types=1);

namespace App\Form;

use App\Repository\PlaceRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
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

        $builder->add('width', IntegerType::class, [
            'label' => 'Sirka (cm)',
            'attr' => [
                'placeholder' => '200',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('height', IntegerType::class, [
            'label' => 'Vyska (cm)',
            'attr' => [
                'placeholder' => '250',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('length', IntegerType::class, [
            'label' => 'Delka (cm)',
            'attr' => [
                'placeholder' => '300',
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
