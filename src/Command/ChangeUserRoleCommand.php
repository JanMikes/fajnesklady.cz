<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\UserRole;
use Symfony\Component\Uid\Uuid;

final readonly class ChangeUserRoleCommand
{
    public function __construct(
        public Uuid $userId,
        public UserRole $role,
    ) {
    }
}
