<?php

declare(strict_types=1);

namespace App\Value;

final readonly class GoPayPaymentStatus
{
    public function __construct(
        public string $id,
        public string $state,
        public ?string $parentId,
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

    public function isPending(): bool
    {
        return in_array($this->state, ['CREATED', 'PAYMENT_METHOD_CHOSEN', 'AUTHORIZED'], true);
    }
}
