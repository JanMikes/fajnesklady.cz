<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

/**
 * Persist a failed refund attempt in the order history. The refund command
 * rolled back, so this runs in its own transaction (MESSENGER.md §3).
 */
final readonly class RecordPaymentRefundFailureCommand
{
    public function __construct(
        public Uuid $contractId,
        public Uuid $paymentId,
        public ?int $amount,
        public string $failureReason,
        public ?string $reason,
    ) {
    }
}
