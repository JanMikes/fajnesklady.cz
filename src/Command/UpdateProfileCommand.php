<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class UpdateProfileCommand
{
    public function __construct(
        public Uuid $userId,
        public string $firstName,
        public string $lastName,
        public ?string $phone,
    ) {
    }
}
