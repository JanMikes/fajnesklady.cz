<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\StorageType;
use App\Entity\User;
use App\Repository\PlaceRepository;
use App\Repository\StorageTypeRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
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
            ],
        ]);

        if (!$options['is_edit']) {
            $builder->add('placeId', ChoiceType::class, [
                'label' => 'Misto',
                'choices' => $this->getPlaceChoices(),
                'placeholder' => '-- Vyberte misto --',
            ]);

            $builder->add('storageTypeId', ChoiceType::class, [
                'label' => 'Typ skladu',
                'choices' => $this->getStorageTypeChoices(),
                'placeholder' => '-- Vyberte typ skladu --',
            ]);
        }

        if (!$options['is_edit']) {
            $builder->add('coordinateX', IntegerType::class, [
                'label' => 'Pozice X',
                'attr' => [
                    'min' => 0,
                ],
            ]);

            $builder->add('coordinateY', IntegerType::class, [
                'label' => 'Pozice Y',
                'attr' => [
                    'min' => 0,
                ],
            ]);

            $builder->add('coordinateWidth', IntegerType::class, [
                'label' => 'Sirka',
                'attr' => [
                    'min' => 1,
                ],
            ]);

            $builder->add('coordinateHeight', IntegerType::class, [
                'label' => 'Vyska',
                'attr' => [
                    'min' => 1,
                ],
            ]);

            $builder->add('coordinateRotation', IntegerType::class, [
                'label' => 'Rotace (stupne)',
                'attr' => [
                    'min' => 0,
                    'max' => 360,
                ],
            ]);
        }

        /** @var StorageType|null $storageType */
        $storageType = $options['storage_type'];
        if (null !== $storageType && !$storageType->uniformStorages) {
            $builder->add('pricePerWeek', NumberType::class, [
                'label' => 'Vlastni cena za tyden (CZK)',
                'required' => false,
                'scale' => 2,
                'attr' => [
                    'placeholder' => 'Pouzije se vychozi cena typu',
                    'step' => '0.01',
                ],
                'help' => 'Nechte prazdne pro pouziti vychozi ceny typu skladu',
            ]);

            $builder->add('pricePerMonth', NumberType::class, [
                'label' => 'Vlastni cena za mesic (CZK)',
                'required' => false,
                'scale' => 2,
                'attr' => [
                    'placeholder' => 'Pouzije se vychozi cena typu',
                    'step' => '0.01',
                ],
                'help' => 'Nechte prazdne pro pouziti vychozi ceny typu skladu',
            ]);
        }

        $builder->add('photos', FileType::class, [
            'label' => 'Fotografie',
            'required' => false,
            'multiple' => true,
            'attr' => [
                'accept' => 'image/jpeg,image/png,image/webp',
            ],
            'help' => 'Nahrajte fotografie skladu (JPEG, PNG, WebP, max 5 MB kazda)',
        ]);

        // Commission rate - only for admins
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            $builder->add('commissionRate', NumberType::class, [
                'label' => 'Provize pro pronajimatele (%)',
                'required' => false,
                'scale' => 0,
                'attr' => [
                    'placeholder' => 'Pouzije se vychozi provize pronajimatele',
                    'min' => 0,
                    'max' => 100,
                ],
                'help' => 'Nechte prazdne pro pouziti vychozi provize pronajimatele (nebo 90%)',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StorageFormData::class,
            'storage_type' => null,
            'is_edit' => false,
        ]);
        $resolver->setAllowedTypes('storage_type', ['null', StorageType::class]);
        $resolver->setAllowedTypes('is_edit', 'bool');
    }

    /**
     * @return array<string, string>
     */
    private function getPlaceChoices(): array
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if (!$user instanceof User) {
            return [];
        }

        // Admins can see all places
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            $places = $this->placeRepository->findAll();
        } else {
            $places = $this->placeRepository->findByOwner($user);
        }

        $choices = [];
        foreach ($places as $place) {
            $choices[$place->name.' ('.$place->city.')'] = $place->id->toRfc4122();
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function getStorageTypeChoices(): array
    {
        $storageTypes = $this->storageTypeRepository->findAllActive();

        $choices = [];
        foreach ($storageTypes as $storageType) {
            $label = $storageType->name.' ('.$storageType->getDimensionsInMeters().') - '.$storageType->place->name;
            $choices[$label] = $storageType->id->toRfc4122();
        }

        return $choices;
    }
}
