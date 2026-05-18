<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class ManualBillingPaymentRequested
{
    public function __construct(
        public Uuid $contractId,
        public Uuid $manualPaymentRequestId,
        public string $stage,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
