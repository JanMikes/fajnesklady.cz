<?php

declare(strict_types=1);

namespace App\Value;

/**
 * What a tenant still owes after stopping card payments — one source for the
 * cancel page and both cancellation e-mails, so they always state the same
 * dates and amounts. Dates are whole days; amounts in haléře.
 */
final readonly class CardPaymentStopSummary
{
    public function __construct(
        public ?\DateTimeImmutable $paidThroughDate,
        public \DateTimeImmutable $endDate,
        /** Due date of the next unpaid cycle; null = nothing more to pay. */
        public ?\DateTimeImmutable $nextDueDate,
        public ?int $nextAmount,
        public int $daysOverdue,
        /** Last day the overdue cycle can be paid before termination (VOP čl. XI.4.b). */
        public ?\DateTimeImmutable $lastDayToPay,
        /** First day the contract can be terminated for non-payment. */
        public ?\DateTimeImmutable $terminationFrom,
        public int $overdueTerminationDays,
        public ?string $variableSymbol,
        public ?string $cardSetupUrl,
    ) {
    }

    public function hasMoreToPay(): bool
    {
        return null !== $this->nextDueDate;
    }

    public function isOverdue(): bool
    {
        return $this->daysOverdue > 0;
    }
}
