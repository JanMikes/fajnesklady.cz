<?php

declare(strict_types=1);

namespace App\Admin\Command;

use App\User\Repository\UserRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ChangeUserRoleHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {
    }

    public function __invoke(ChangeUserRoleCommand $command): void
    {
        $user = $this->userRepository->findById($command->userId);

        if (null === $user) {
            throw new \RuntimeException('User not found');
        }

        $user->changeRole($command->newRole);
        $this->userRepository->save($user);
    }
}
