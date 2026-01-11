<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Event\UserRegistered;
use App\Exception\UserAlreadyExistsException;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsMessageHandler]
final readonly class RegisterUserHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RegisterUserCommand $command): void
    {
        // Check email uniqueness
        $existingUser = $this->userRepository->findByEmail($command->email);
        if (null !== $existingUser) {
            throw UserAlreadyExistsException::withEmail($command->email);
        }

        $now = $this->clock->now();

        // Create new User entity
        $user = User::create(
            email: $command->email,
            name: $command->name,
            password: '', // Will be hashed below
            now: $now,
        );

        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $command->password);
        $user->changePassword($hashedPassword, $now);

        // Save user
        $this->userRepository->save($user);

        // Dispatch UserRegistered event
        $this->eventBus->dispatch(new UserRegistered(
            userId: $user->getId(),
            email: $user->getEmail(),
            name: $user->getName(),
            occurredOn: $now,
        ));
    }
}
