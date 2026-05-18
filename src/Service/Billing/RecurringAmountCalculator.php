<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Contract;
use App\Service\PriceCalculator;

/**
 * Single source of truth for the expected amount of any recurring charge
 * against a Contract. Mirrors the proration rule used by `PriceCalculator::buildPaymentSchedule()`
 * for fixed-end contracts (last billing cycle is prorated by remaining days
 * over a 30-day month).
 *
 * Both `ChargeRecurringPaymentHandler` (cron) and `ProcessPaymentNotificationHandler`
 * (webhook) call this so the "what should we charge?" answer is identical
 * across surfaces — and so the webhook can detect mismatches when GoPay
 * reports a different amount than what we expected.
 */
final readonly class RecurringAmountCalculator
{
    private const int DAYS_PER_MONTH = 30;

    /**
     * Expected amount in halere for the recurring charge happening at $now
     * against $contract.
     *
     *  - Open-ended contract → full monthly rate.
     *  - Fixed-end contract, last cycle → prorated by remaining days.
     *  - Fixed-end contract, regular cycle → full monthly rate.
     *
     * The "billing period start" is the contract's `nextBillingDate` if set,
     * otherwise $now — matches the deterministic anchor used by the cron.
     */
    public function calculate(Contract $contract, \DateTimeImmutable $now): int
    {
        $monthlyRate = $contract->getEffectiveMonthlyAmount();
        $effectiveEndDate = $contract->getEffectiveEndDate();

        if (null === $effectiveEndDate) {
            return $monthlyRate;
        }

        $billingPeriodStart = $contract->nextBillingDate ?? $now;
        $nextFullPeriodEnd = $billingPeriodStart->modify('+1 month');

        if ($nextFullPeriodEnd <= $effectiveEndDate) {
            return $monthlyRate;
        }

        // Last cycle: prorate by remaining days over a 30-day month, rounded UP
        // to whole CZK so this matches the schedule shown at order creation
        // (see PriceCalculator::roundUpToWholeCzk).
        $remainingDays = max(1, (int) $billingPeriodStart->diff($effectiveEndDate)->days);

        return max(100, PriceCalculator::roundUpToWholeCzk($remainingDays * $monthlyRate / self::DAYS_PER_MONTH));
    }
}
