<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Exception\UserAlreadyExists;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsMessageHandler]
final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(RegisterUserCommand $command): void
    {
        // Check email uniqueness
        $existingUser = $this->userRepository->findByEmail($command->email);
        if (null !== $existingUser) {
            throw UserAlreadyExists::withEmail($command->email);
        }

        $now = $this->clock->now();

        $user = new User(
            id: $this->identityProvider->next(),
            email: $command->email,
            password: '', // Will be hashed below
            name: $command->name,
            createdAt: $now,
        );

        $hashedPassword = $this->passwordHasher->hashPassword($user, $command->password);
        $user->changePassword($hashedPassword, $now);

        $this->userRepository->save($user);
    }
}
