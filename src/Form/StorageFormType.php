<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Repository\PlaceRepository;
use App\Repository\StorageTypeRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @extends AbstractType<StorageFormData>
 */
class StorageFormType extends AbstractType
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageTypeRepository $storageTypeRepository,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('number', TextType::class, [
            'label' => 'Cislo skladu',
            'attr' => [
                'placeholder' => 'napr. A1, B12',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('storageTypeId', ChoiceType::class, [
            'label' => 'Typ skladu',
            'choices' => $this->getStorageTypeChoices(),
            'attr' => [
                'class' => 'select select-bordered w-full',
            ],
        ]);

        $builder->add('coordinateX', IntegerType::class, [
            'label' => 'Pozice X',
            'attr' => [
                'class' => 'input input-bordered w-full',
                'min' => 0,
            ],
        ]);

        $builder->add('coordinateY', IntegerType::class, [
            'label' => 'Pozice Y',
            'attr' => [
                'class' => 'input input-bordered w-full',
                'min' => 0,
            ],
        ]);

        $builder->add('coordinateWidth', IntegerType::class, [
            'label' => 'Sirka',
            'attr' => [
                'class' => 'input input-bordered w-full',
                'min' => 1,
            ],
        ]);

        $builder->add('coordinateHeight', IntegerType::class, [
            'label' => 'Vyska',
            'attr' => [
                'class' => 'input input-bordered w-full',
                'min' => 1,
            ],
        ]);

        $builder->add('coordinateRotation', IntegerType::class, [
            'label' => 'Rotace (stupne)',
            'attr' => [
                'class' => 'input input-bordered w-full',
                'min' => 0,
                'max' => 360,
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StorageFormData::class,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function getStorageTypeChoices(): array
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if (!$user instanceof User) {
            return [];
        }

        // Admins can see all storage types
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            $places = $this->placeRepository->findAll();
        } else {
            $places = $this->placeRepository->findByOwner($user);
        }

        $choices = [];
        foreach ($places as $place) {
            $storageTypes = $this->storageTypeRepository->findByPlace($place);
            foreach ($storageTypes as $storageType) {
                $label = $place->name.' - '.$storageType->name.' ('.$storageType->getDimensionsInMeters().')';
                $choices[$label] = $storageType->id->toRfc4122();
            }
        }

        return $choices;
    }
}
