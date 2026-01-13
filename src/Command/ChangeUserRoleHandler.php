<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ChangeUserRoleHandler
{
    public function __construct(
        private UserRepository $userRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ChangeUserRoleCommand $command): void
    {
        $user = $this->userRepository->get($command->userId);
        $user->changeRole($command->role, $this->clock->now());
        $this->userRepository->save($user);
    }
}
