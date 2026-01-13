<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @extends AbstractType<StorageUnavailabilityFormData>
 */
class StorageUnavailabilityFormType extends AbstractType
{
    public function __construct(
        private readonly PlaceRepository $placeRepository,
        private readonly StorageRepository $storageRepository,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('storageId', ChoiceType::class, [
            'label' => 'Sklad',
            'choices' => $this->getStorageChoices(),
            'attr' => [
                'class' => 'select select-bordered w-full',
            ],
            'placeholder' => '-- Vyberte sklad --',
        ]);

        $builder->add('startDate', DateType::class, [
            'label' => 'Datum zacatku',
            'widget' => 'single_text',
            'attr' => [
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('endDate', DateType::class, [
            'label' => 'Datum konce',
            'widget' => 'single_text',
            'required' => false,
            'attr' => [
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('indefinite', CheckboxType::class, [
            'label' => 'Neomezene (bez data konce)',
            'required' => false,
            'attr' => [
                'class' => 'checkbox',
            ],
        ]);

        $builder->add('reason', TextareaType::class, [
            'label' => 'Duvod blokovani',
            'attr' => [
                'class' => 'textarea textarea-bordered w-full',
                'rows' => 3,
                'placeholder' => 'napr. udrzba, renovace, rezervace pro konkretniho zakaznika...',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StorageUnavailabilityFormData::class,
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function getStorageChoices(): array
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if (!$user instanceof User) {
            return [];
        }

        // Admins can see all storages
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            $places = $this->placeRepository->findAll();
        } else {
            $places = $this->placeRepository->findByOwner($user);
        }

        $choices = [];
        foreach ($places as $place) {
            $storages = $this->storageRepository->findByPlace($place);
            foreach ($storages as $storage) {
                $label = $place->name . ' - ' . $storage->storageType->name . ' - ' . $storage->number;
                $choices[$label] = $storage->id->toRfc4122();
            }
        }

        return $choices;
    }
}
