<?php

declare(strict_types=1);

namespace App\Value;

/**
 * One line of GoPay's refund history for a payment
 * (GET /payments/payment/{id}/refunds). GoPay gives refunds no id of their
 * own, so reconciliation works on totals per state.
 */
final readonly class GoPayRefundHistoryEntry
{
    public const string STATE_SUCCESS = 'SUCCESS';
    public const string STATE_FAILED = 'FAILED';
    public const string STATE_REQUESTED = 'REQUESTED';

    public function __construct(
        public string $state,
        /** Haléře. */
        public int $amount,
        public ?\DateTimeImmutable $requestedOn = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return self::STATE_SUCCESS === $this->state;
    }

    public function isRequested(): bool
    {
        return self::STATE_REQUESTED === $this->state;
    }
}
