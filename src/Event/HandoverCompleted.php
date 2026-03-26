<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class HandoverCompleted
{
    public function __construct(
        public Uuid $handoverProtocolId,
        public Uuid $contractId,
        public ?string $newLockCode,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
