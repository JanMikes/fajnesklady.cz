<?php

declare(strict_types=1);

namespace App\Admin\Command;

use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChangeUserRoleCommand
{
    public function __construct(
        public Uuid $userId,
        #[Assert\NotBlank(message: 'Role is required')]
        #[Assert\Choice(
            choices: ['ROLE_USER', 'ROLE_ADMIN'],
            message: 'Please select a valid role'
        )]
        public string $newRole,
    ) {
    }
}
