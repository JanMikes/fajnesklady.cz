<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Contract;
use App\Enum\BillingMode;
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
    public function __construct(
        private PriceCalculator $priceCalculator,
    ) {
    }

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
        return $this->calculateForPeriodStart($contract, $contract->nextBillingDate ?? $now);
    }

    /**
     * What we actually ask the customer to send: the cycle amount less any credit
     * the contract is already holding (spec 091 D3). A contract sitting on a
     * 400 Kč over-payment facing a 3 100 Kč cycle is asked for 2 700 Kč.
     *
     * Request-side only. {@see \App\Service\Payment\PaymentAllocator} still works
     * against the FULL cycle amount from {@see self::calculate()} and adds the
     * credit to the money available — subtracting it here as well would count it
     * twice. The two agree: the customer sends 2 700, the allocator sees
     * 400 + 2 700 = 3 100, the cycle settles and the credit drains to zero.
     */
    public function amountToRequest(Contract $contract, \DateTimeImmutable $now): int
    {
        return max(0, $this->calculate($contract, $now) - $contract->creditBalance);
    }

    /**
     * Same rules as {@see self::calculate()}, but for an explicit billing
     * period start — used to project FUTURE cycles (admin payment overview)
     * without touching the contract's live anchor.
     */
    public function calculateForPeriodStart(Contract $contract, \DateTimeImmutable $billingPeriodStart): int
    {
        // Spec 078 tranches: an upfront contract with a billing anchor bills
        // the remaining rental in yearly tranches. Each tranche is the next
        // up-to-12 months of the SAME monthly walk the upfront schedule was
        // built from (full tranche = 12 × monthly rate, final tranche =
        // remaining months + prorated tail) — never a flat monthly/yearly rate.
        // The rate is the LOCKED order rate (firstPaymentPrice / 12), not the
        // live storage price: the customer prepays exactly what was quoted,
        // and later admin price-list edits must not shift the tranches.
        if (BillingMode::ONE_TIME === $contract->billingMode) {
            $order = $contract->order;
            $monthlyRate = $order->isPaidInUpfrontTranches()
                ? $order->getUpfrontLockedMonthlyRate()
                : $contract->getEffectiveMonthlyAmount(); // defensive: unreachable for ≤ 12-month upfront (no anchor)

            return $this->priceCalculator->calculateUpfrontTrancheAmount(
                $monthlyRate,
                $billingPeriodStart,
                $contract->getEffectiveEndDate(),
            );
        }

        $periodAmount = $contract->getEffectiveRecurringAmount();
        $periodDays = $contract->getBillingPeriodDays();
        $effectiveEndDate = $contract->getEffectiveEndDate();

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
