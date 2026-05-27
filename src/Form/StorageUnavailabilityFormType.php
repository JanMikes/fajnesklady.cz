<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Repository\StorageRepository;
use App\Service\Form\StorageChoiceBuilder;
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
        private readonly StorageRepository $storageRepository,
        private readonly StorageChoiceBuilder $storageChoiceBuilder,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('storageId', ChoiceType::class, [
            'label' => 'Sklad',
            'choices' => $this->getStorageChoices(),
            'placeholder' => '-- Vyberte sklad --',
            'attr' => ['data-controller' => 'tom-select'],
        ]);

        $builder->add('startDate', DateType::class, [
            'label' => 'Datum začátku',
            'widget' => 'single_text',
        ]);

        $builder->add('endDate', DateType::class, [
            'label' => 'Datum konce',
            'widget' => 'single_text',
            'required' => false,
        ]);

        $builder->add('indefinite', CheckboxType::class, [
            'label' => 'Neomezené (bez data konce)',
            'required' => false,
        ]);

        $builder->add('reason', TextareaType::class, [
            'label' => 'Důvod blokování',
            'empty_data' => '',
            'attr' => [

                'rows' => 3,
                'placeholder' => 'např. údržba, renovace, rezervace pro konkrétního zákazníka...',
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StorageUnavailabilityFormData::class,
            'csrf_protection' => false,
        ]);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function getStorageChoices(): array
    {
        $token = $this->tokenStorage->getToken();
        $user = $token?->getUser();

        if (!$user instanceof User) {
            return [];
        }

        $storages = $this->authorizationChecker->isGranted('ROLE_ADMIN')
            ? $this->storageRepository->findAll()
            : $this->storageRepository->findByOwner($user);

        return $this->storageChoiceBuilder->groupAndSort($storages);
    }
}
