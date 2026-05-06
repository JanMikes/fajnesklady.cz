<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class UpdatePlaceStorageCodeConfigCommand
{
    public function __construct(
        public Uuid $placeId,
        public bool $enabled,
        public int $digits,
        public int $from,
        public int $to,
    ) {
    }
}
