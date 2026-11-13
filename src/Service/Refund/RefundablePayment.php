<?php

declare(strict_types=1);

namespace App\Service\Refund;

use App\Entity\Invoice;
use App\Entity\Payment;

/**
 * The card payment an admin may refund when terminating a contract, with the
 * period it paid for and the invoice a credit note would correct.
 */
final readonly class RefundablePayment
{
    public function __construct(
        public Payment $payment,
        public string $goPayPaymentId,
        public ?\DateTimeImmutable $periodStart,
        /** Exclusive — the next billing date / paidThroughDate. */
        public ?\DateTimeImmutable $periodEnd,
        public ?Invoice $originalInvoice,
        /** Why no credit note can be issued (null when $originalInvoice is set). */
        public ?string $creditNoteUnavailableReason,
    ) {
    }

    public function canProrate(): bool
    {
        return null !== $this->periodStart
            && null !== $this->periodEnd
            && RefundCalculator::days($this->periodStart, $this->periodEnd) > 0;
    }

    public function canIssueCreditNote(): bool
    {
        return null !== $this->originalInvoice;
    }

    /** Last paid day (inclusive) for display. */
    public function periodLastDay(): ?\DateTimeImmutable
    {
        return $this->periodEnd?->modify('-1 day');
    }

    public function periodDays(): ?int
    {
        return null !== $this->periodStart && null !== $this->periodEnd
            ? RefundCalculator::days($this->periodStart, $this->periodEnd)
            : null;
    }
}
