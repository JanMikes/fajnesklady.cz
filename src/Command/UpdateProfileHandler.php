<?php

declare(strict_types=1);

namespace App\Command;

use App\Exception\UserNotFound;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UpdateProfileHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(UpdateProfileCommand $command): void
    {
        $user = $this->userRepository->find($command->userId);

        if (null === $user) {
            throw UserNotFound::withId($command->userId);
        }

        $now = $this->clock->now();
        $user->updateProfile(
            $command->firstName,
            $command->lastName,
            $command->phone,
            $now,
        );
        $user->updateBankAccount($command->bankAccountNumber, $command->bankCode, $now);
        $this->userRepository->save($user);
    }
}
