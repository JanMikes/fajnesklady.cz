<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\InvalidCurrentPassword;
use App\Exception\UserNotFound;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsMessageHandler]
final readonly class ChangePasswordHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ChangePasswordCommand $command): void
    {
        $user = $this->userRepository->find($command->userId);

        if (null === $user) {
            throw UserNotFound::withId($command->userId);
        }

        if (!$this->passwordHasher->isPasswordValid($user, $command->currentPassword)) {
            throw InvalidCurrentPassword::create();
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $command->newPassword);
        $user->changePassword($hashedPassword, $this->clock->now());
        $this->userRepository->save($user);
    }
}
