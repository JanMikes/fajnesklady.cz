<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class ContractTerminatedDueToPaymentFailure
{
    public function __construct(
        public Uuid $contractId,
        public int $outstandingDebtAmount,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
