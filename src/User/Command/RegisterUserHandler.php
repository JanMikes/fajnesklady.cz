<?php

declare(strict_types=1);

namespace App\User\Command;

use App\User\Entity\User;
use App\User\Event\UserRegistered;
use App\User\Exception\UserAlreadyExistsException;
use App\User\Repository\UserRepository;
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
    ) {
    }

    public function __invoke(RegisterUserCommand $command): void
    {
        // Check email uniqueness
        $existingUser = $this->userRepository->findByEmail($command->email);
        if (null !== $existingUser) {
            throw UserAlreadyExistsException::withEmail($command->email);
        }

        // Create new User entity
        $user = User::create(
            email: $command->email,
            name: $command->name,
            password: '', // Will be hashed below
        );

        // Hash password
        $hashedPassword = $this->passwordHasher->hashPassword($user, $command->password);
        $user->changePassword($hashedPassword);

        // Save user
        $this->userRepository->save($user);

        // Dispatch UserRegistered event
        $this->eventBus->dispatch(new UserRegistered(
            userId: $user->getId(),
            email: $user->getEmail(),
            name: $user->getName(),
        ));
    }
}
