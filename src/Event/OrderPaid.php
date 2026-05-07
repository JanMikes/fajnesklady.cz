<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class OrderPaid
{
    /**
     * @param ?int $amountOverride Halere amount that should be recorded as the
     *                             initial Payment when it differs from the
     *                             order's locked-in monthly. Used by the admin
     *                             migrate flow (lump-sum prepayment). Null for
     *                             every other path.
     */
    public function __construct(
        public Uuid $orderId,
        public \DateTimeImmutable $occurredOn,
        public ?int $amountOverride = null,
    ) {
    }
}
