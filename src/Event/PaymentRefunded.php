<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

/**
 * GoPay confirmed that money went back to the customer's card. Recorded by
 * PaymentRefund::markSucceeded() — the ledger storno row is already written.
 */
final readonly class PaymentRefunded
{
    public function __construct(
        public Uuid $refundId,
        public Uuid $orderId,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
