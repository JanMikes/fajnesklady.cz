<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class PlaceProposed
{
    public function __construct(
        public Uuid $placeId,
        public Uuid $proposedById,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
