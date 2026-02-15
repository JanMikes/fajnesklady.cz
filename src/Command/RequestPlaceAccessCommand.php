<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class RequestPlaceAccessCommand
{
    public function __construct(
        public Uuid $placeId,
        public Uuid $requestedById,
        public ?string $message = null,
    ) {
    }
}
