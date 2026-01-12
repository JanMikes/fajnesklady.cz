<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GetOrCreateUserByEmailHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ClockInterface $clock,
        private ProvideIdentity $identityProvider,
    ) {
    }

    /**
     * Returns existing user or creates a new passwordless user.
     */
    public function __invoke(GetOrCreateUserByEmailCommand $command): User
    {
        $existingUser = $this->userRepository->findByEmail($command->email);

        if (null !== $existingUser) {
            return $existingUser;
        }

        // Create passwordless user
        $now = $this->clock->now();

        $user = new User(
            id: $this->identityProvider->next(),
            email: $command->email,
            password: null,
            firstName: $command->firstName,
            lastName: $command->lastName,
            createdAt: $now,
        );

        if (null !== $command->phone) {
            $user->updateProfile($command->firstName, $command->lastName, $command->phone, $now);
        }

        $this->userRepository->save($user);

        return $user;
    }
}
