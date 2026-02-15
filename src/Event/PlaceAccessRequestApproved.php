<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class PlaceAccessRequestApproved
{
    public function __construct(
        public Uuid $requestId,
        public Uuid $placeId,
        public Uuid $landlordId,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
