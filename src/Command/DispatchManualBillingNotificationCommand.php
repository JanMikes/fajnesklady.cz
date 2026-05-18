<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class DispatchManualBillingNotificationCommand
{
    public function __construct(
        public Uuid $contractId,
        public \DateTimeImmutable $periodStart,
        public string $stage,
    ) {
    }
}
