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
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

/**
 * @extends AbstractType<array<string, mixed>>
 */
class StorageTypeType extends AbstractType
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nazev',
                'constraints' => [
                    new NotBlank(message: 'Zadejte nazev'),
                    new Length(max: 255, maxMessage: 'Nazev nemuze byt delsi nez {{ limit }} znaku'),
                ],
                'attr' => [
                    'placeholder' => 'Nazev typu skladu',
                    'class' => 'input input-bordered w-full',
                ],
            ])
            ->add('width', NumberType::class, [
                'label' => 'Sirka (m)',
                'scale' => 2,
                'constraints' => [
                    new NotBlank(message: 'Zadejte sirku'),
                    new Positive(message: 'Sirka musi byt kladne cislo'),
                ],
                'attr' => [
                    'placeholder' => '2.00',
                    'class' => 'input input-bordered w-full',
                    'step' => '0.01',
                ],
            ])
            ->add('height', NumberType::class, [
                'label' => 'Vyska (m)',
                'scale' => 2,
                'constraints' => [
                    new NotBlank(message: 'Zadejte vysku'),
                    new Positive(message: 'Vyska musi byt kladne cislo'),
                ],
                'attr' => [
                    'placeholder' => '2.50',
                    'class' => 'input input-bordered w-full',
                    'step' => '0.01',
                ],
            ])
            ->add('length', NumberType::class, [
                'label' => 'Delka (m)',
                'scale' => 2,
                'constraints' => [
                    new NotBlank(message: 'Zadejte delku'),
                    new Positive(message: 'Delka musi byt kladne cislo'),
                ],
                'attr' => [
                    'placeholder' => '3.00',
                    'class' => 'input input-bordered w-full',
                    'step' => '0.01',
                ],
            ])
            ->add('pricePerWeek', NumberType::class, [
                'label' => 'Cena za tyden (CZK)',
                'scale' => 2,
                'constraints' => [
                    new NotBlank(message: 'Zadejte cenu za tyden'),
                    new PositiveOrZero(message: 'Cena musi byt nula nebo kladne cislo'),
                ],
                'attr' => [
                    'placeholder' => '500.00',
                    'class' => 'input input-bordered w-full',
                    'step' => '0.01',
                ],
            ])
            ->add('pricePerMonth', NumberType::class, [
                'label' => 'Cena za mesic (CZK)',
                'scale' => 2,
                'constraints' => [
                    new NotBlank(message: 'Zadejte cenu za mesic'),
                    new PositiveOrZero(message: 'Cena musi byt nula nebo kladne cislo'),
                ],
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
                'constraints' => [
                    new NotBlank(message: 'Vyberte vlastnika'),
                ],
                'attr' => [
                    'class' => 'select select-bordered w-full',
                ],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
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
