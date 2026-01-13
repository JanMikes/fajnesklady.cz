<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\UserNotFound;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsMessageHandler]
final readonly class SetUserPasswordHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private UserPasswordHasherInterface $passwordHasher,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SetUserPasswordCommand $command): void
    {
        $user = $this->userRepository->find($command->userId);

        if (null === $user) {
            throw UserNotFound::withId($command->userId);
        }

        $hashedPassword = $this->passwordHasher->hashPassword($user, $command->plainPassword);
        $user->changePassword($hashedPassword, $this->clock->now());
        $this->userRepository->save($user);
    }
}
