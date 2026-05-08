<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class OverdueDigestRequested
{
    public function __construct(
        public Uuid $adminId,
        public \DateTimeImmutable $occurredOn,
        public \DateTimeImmutable $date,
    ) {
    }
}
