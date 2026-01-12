<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\UserRole;
use App\Repository\UserRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
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
        private readonly UserRepository $userRepository,
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

        $builder->add('width', NumberType::class, [
            'label' => 'Sirka (m)',
            'scale' => 2,
            'attr' => [
                'placeholder' => '2.00',
                'class' => 'input input-bordered w-full',
                'step' => '0.01',
            ],
        ]);

        $builder->add('height', NumberType::class, [
            'label' => 'Vyska (m)',
            'scale' => 2,
            'attr' => [
                'placeholder' => '2.50',
                'class' => 'input input-bordered w-full',
                'step' => '0.01',
            ],
        ]);

        $builder->add('length', NumberType::class, [
            'label' => 'Delka (m)',
            'scale' => 2,
            'attr' => [
                'placeholder' => '3.00',
                'class' => 'input input-bordered w-full',
                'step' => '0.01',
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

        // Only show owner selector for admins
        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            $builder->add('ownerId', ChoiceType::class, [
                'label' => 'Vlastnik',
                'choices' => $this->getOwnerChoices(),
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
    private function getOwnerChoices(): array
    {
        $users = $this->userRepository->findAll();
        $choices = [];

        foreach ($users as $user) {
            $roles = $user->getRoles();
            if (in_array(UserRole::LANDLORD->value, $roles, true)
                || in_array(UserRole::ADMIN->value, $roles, true)) {
                $choices[$user->name.' ('.$user->email.')'] = $user->id->toRfc4122();
            }
        }

        return $choices;
    }
}
