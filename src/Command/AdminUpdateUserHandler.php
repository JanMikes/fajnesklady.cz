<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AdminUpdateUserHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(AdminUpdateUserCommand $command): void
    {
        $user = $this->userRepository->get($command->userId);
        $now = $this->clock->now();

        $user->updateProfile(
            $command->firstName,
            $command->lastName,
            $command->phone,
            $now,
        );

        $user->updateBillingInfo(
            $command->companyName,
            $command->companyId,
            $command->companyVatId,
            $command->billingStreet,
            $command->billingCity,
            $command->billingPostalCode,
            $now,
        );

        $user->changeRole($command->role, $now);

        $this->userRepository->save($user);
    }
}
