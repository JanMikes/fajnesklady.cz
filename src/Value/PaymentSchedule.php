<?php

declare(strict_types=1);

namespace App\Value;

/**
 * Authoritative payment schedule for a rental — single source of truth shared
 * by the customer-facing quote (order_create / order_accept / order_payment)
 * AND the recurring-billing cron (`ChargeRecurringPaymentHandler`). Whatever
 * appears here is what the customer will be charged, no surprises.
 *
 * For unlimited rentals only the first entry is known; subsequent monthly
 * charges run forever (`isOpenEnded = true`).
 */
final readonly class PaymentSchedule
{
    /**
     * @param list<PaymentScheduleEntry> $entries
     */
    public function __construct(
        public array $entries,
        public bool $isRecurring,
        public bool $isOpenEnded,
        public ?int $monthlyAmount,
        public ?int $yearlyAmount = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->entries;
    }

    public function firstPayment(): PaymentScheduleEntry
    {
        if ([] === $this->entries) {
            throw new \LogicException('Empty payment schedule has no first payment.');
        }

        return $this->entries[0];
    }

    /**
     * Sum of all known charges. For open-ended (unlimited) schedules this is
     * just the first month — there is no "total" to display.
     */
    public function totalKnownAmount(): int
    {
        return array_sum(array_map(static fn (PaymentScheduleEntry $e): int => $e->amount, $this->entries));
    }

    public function totalKnownAmountInCzk(): float
    {
        return $this->totalKnownAmount() / 100;
    }

    public function getMonthlyAmountInCzk(): ?float
    {
        return null === $this->monthlyAmount ? null : $this->monthlyAmount / 100;
    }

    public function isYearly(): bool
    {
        return null !== $this->yearlyAmount;
    }

    public function getYearlyAmountInCzk(): ?float
    {
        return null === $this->yearlyAmount ? null : $this->yearlyAmount / 100;
    }

    /**
     * Customer-facing equivalent monthly figure for yearly schedules — the
     * spec 045 direct user brief is "I want to see how much it will cost me
     * per month, not per year". Returns null for non-yearly schedules.
     */
    public function getYearlyMonthlyEquivalentInCzk(): ?float
    {
        return null === $this->yearlyAmount ? null : $this->yearlyAmount / 12 / 100;
    }

    /**
     * Number of monthly billing cycles for fixed-end recurring schedules.
     * 1 for one-shot or unlimited; ≥ 2 for fixed-end ≥ 28 days.
     */
    public function entryCount(): int
    {
        return count($this->entries);
    }
}
