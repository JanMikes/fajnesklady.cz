<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\RefundMode;
use Symfony\Component\Uid\Uuid;

/**
 * Refund the card payment of a contract the admin just terminated (spec 092).
 * Dispatched AFTER AdminTerminateContractCommand committed — a GoPay refund
 * cannot be undone, so it must never share a transaction that might roll
 * the termination back.
 */
final readonly class RefundContractPaymentCommand
{
    public function __construct(
        public Uuid $contractId,
        /** The payment the admin saw in the form — guards against a stale form. */
        public Uuid $paymentId,
        public RefundMode $mode,
        public ?\DateTimeImmutable $refundFrom,
        public bool $issueCreditNote,
        public ?string $reason,
        public Uuid $adminId,
    ) {
    }
}
