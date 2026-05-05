<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

/**
 * Fired when GoPay confirms the FIRST recurring (ON_DEMAND) payment for an order.
 * Triggers the "your recurring payment was established" confirmation e-mail
 * required by Podmínky opakovaných plateb čl. IV (within 2 working days).
 */
final readonly class RecurringPaymentEstablished
{
    public function __construct(
        public Uuid $orderId,
        public string $goPayParentPaymentId,
        public int $amount,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
