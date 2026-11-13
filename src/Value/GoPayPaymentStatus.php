<?php

declare(strict_types=1);

namespace App\Value;

final readonly class GoPayPaymentStatus
{
    public function __construct(
        public string $id,
        public string $state,
        public ?string $parentId,
        public ?int $amount = null,
    ) {
    }

    public function isPaid(): bool
    {
        return 'PAID' === $this->state;
    }

    public function isCanceled(): bool
    {
        return in_array($this->state, ['CANCELED', 'TIMEOUTED', 'REFUNDED'], true);
    }

    /**
     * Money (partly) went back to the payer. PARTIALLY_REFUNDED is NOT in
     * isCanceled(): the rest of the payment still stands.
     */
    public function isRefunded(): bool
    {
        return in_array($this->state, ['REFUNDED', 'PARTIALLY_REFUNDED'], true);
    }

    public function isPending(): bool
    {
        return in_array($this->state, ['CREATED', 'PAYMENT_METHOD_CHOSEN', 'AUTHORIZED'], true);
    }
}
