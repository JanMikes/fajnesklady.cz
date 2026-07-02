<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Contract;
use App\Service\PriceCalculator;

/**
 * Single source of truth for the expected amount of any recurring charge
 * against a Contract. Mirrors the proration rule used by `PriceCalculator::buildPaymentSchedule()`
 * for fixed-end contracts (last billing cycle is prorated by remaining days
 * over the cadence period — 30 days monthly, 365 days yearly).
 *
 * Both `ChargeRecurringPaymentHandler` (cron) and `ProcessPaymentNotificationHandler`
 * (webhook) call this so the "what should we charge?" answer is identical
 * across surfaces — and so the webhook can detect mismatches when GoPay
 * reports a different amount than what we expected.
 */
final readonly class RecurringAmountCalculator
{
    /**
     * Expected amount in halere for the recurring charge happening at $now
     * against $contract.
     *
     *  - Last cycle → prorated by remaining days.
     *  - Regular cycle → full cadence-period rate.
     *
     * The "billing period start" is the contract's `nextBillingDate` if set,
     * otherwise $now — matches the deterministic anchor used by the cron.
     * For YEARLY contracts the rate is the yearly amount and proration uses
     * 365 days; for MONTHLY it's the (possibly individual) monthly amount
     * over a 30-day month.
     */
    public function calculate(Contract $contract, \DateTimeImmutable $now): int
    {
        $periodAmount = $contract->getEffectiveRecurringAmount();
        $periodDays = $contract->getBillingPeriodDays();
        $effectiveEndDate = $contract->getEffectiveEndDate();

        $billingPeriodStart = $contract->nextBillingDate ?? $now;
        $nextFullPeriodEnd = $billingPeriodStart->modify($contract->getBillingCadenceStep());

        if ($nextFullPeriodEnd <= $effectiveEndDate) {
            return $periodAmount;
        }

        // Last cycle: prorate by remaining days over the cadence period, rounded
        // UP to whole CZK so this matches the schedule shown at order creation
        // (see PriceCalculator::roundUpToWholeCzk).
        $remainingDays = max(1, (int) $billingPeriodStart->diff($effectiveEndDate)->days);

        return max(100, PriceCalculator::roundUpToWholeCzk($remainingDays * $periodAmount / $periodDays));
    }
}
