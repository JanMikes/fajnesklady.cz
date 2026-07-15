<?php

declare(strict_types=1);

namespace App\Service\Order;

/**
 * One line of the admin "Přehled plateb" table — a single expected or actual
 * money event of an order/contract lifecycle: the first payment, a billing
 * cycle (requested, paid or still only projected), the externally-prepaid
 * onboarding period, or the migrated-in debt.
 */
final readonly class PaymentOverviewRow
{
    public const string STATUS_PAID = 'paid';
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_OVERDUE = 'overdue';
    public const string STATUS_SCHEDULED = 'scheduled';
    public const string STATUS_COVERED_EXTERNAL = 'covered_external';
    public const string STATUS_CANCELLED = 'cancelled';

    public function __construct(
        public string $label,
        public string $status,
        public ?\DateTimeImmutable $dueDate = null,
        public ?\DateTimeImmutable $periodStart = null,
        public ?\DateTimeImmutable $periodEnd = null,
        public ?int $amountInHaler = null,
        public ?\DateTimeImmutable $paidAt = null,
        public ?string $source = null,
        public ?string $note = null,
        public ?int $daysOverdue = null,
    ) {
    }

    /**
     * Chronological anchor for sorting the table.
     */
    public function sortDate(): ?\DateTimeImmutable
    {
        return $this->periodStart ?? $this->dueDate ?? $this->paidAt;
    }
}
