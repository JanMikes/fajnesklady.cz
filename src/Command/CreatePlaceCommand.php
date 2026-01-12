<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class CreatePlaceCommand
{
    public function __construct(
        public string $name,
        public string $address,
        public ?string $description,
        public Uuid $ownerId,
    ) {
    }
}
