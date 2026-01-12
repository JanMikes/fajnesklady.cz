<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\UserRole;
use App\Repository\UserRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @extends AbstractType<PlaceFormData>
 */
class PlaceFormType extends AbstractType
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
                'placeholder' => 'Nazev mista',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('address', TextType::class, [
            'label' => 'Adresa',
            'attr' => [
                'placeholder' => 'Ulice, Mesto, PSC',
                'class' => 'input input-bordered w-full',
            ],
        ]);

        $builder->add('description', TextareaType::class, [
            'label' => 'Popis',
            'required' => false,
            'attr' => [
                'placeholder' => 'Volitelny popis mista',
                'class' => 'textarea textarea-bordered w-full',
                'rows' => 4,
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
            'data_class' => PlaceFormData::class,
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
