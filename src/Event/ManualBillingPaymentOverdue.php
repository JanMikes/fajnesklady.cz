<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class ManualBillingPaymentOverdue
{
    public function __construct(
        public Uuid $contractId,
        public Uuid $manualPaymentRequestId,
        public string $stage,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
