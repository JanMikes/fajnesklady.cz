<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Enum\UserRole;
use App\Event\LandlordRegistered;
use App\Exception\UserAlreadyExists;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsMessageHandler]
final readonly class RegisterLandlordHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(RegisterLandlordCommand $command): void
    {
        $existingUser = $this->userRepository->findByEmail($command->email);
        if (null !== $existingUser) {
            throw UserAlreadyExists::withEmail($command->email);
        }

        $now = $this->clock->now();

        $user = new User(
            id: $this->identityProvider->next(),
            email: $command->email,
            password: '',
            firstName: $command->firstName,
            lastName: $command->lastName,
            createdAt: $now,
        );

        // Discard UserRegistered event to prevent email verification
        $user->popEvents();

        $hashedPassword = $this->passwordHasher->hashPassword($user, $command->password);
        $user->changePassword($hashedPassword, $now);

        $user->changeRole(UserRole::LANDLORD, $now);

        $user->updateBillingInfo(
            companyName: $command->companyName,
            companyId: $command->companyId,
            companyVatId: $command->companyVatId,
            billingStreet: $command->billingStreet,
            billingCity: $command->billingCity,
            billingPostalCode: $command->billingPostalCode,
            now: $now,
        );

        if (null !== $command->phone) {
            $user->updateProfile(
                firstName: $command->firstName,
                lastName: $command->lastName,
                phone: $command->phone,
                now: $now,
            );
        }

        $user->recordThat(new LandlordRegistered(
            userId: $user->id,
            email: $user->email,
            companyName: $command->companyName,
            occurredOn: $now,
        ));

        $this->userRepository->save($user);
    }
}
