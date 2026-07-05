<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Enum\PaymentFrequency;
use App\Value\PaymentSchedule;
use App\Value\PaymentScheduleEntry;

final readonly class PriceCalculator
{
    public const int WEEKLY_THRESHOLD_DAYS = 31;
    public const int SHORT_TERM_THRESHOLD_DAYS = 180;
    public const int YEARLY_THRESHOLD_DAYS = 360;

    /**
     * Spec 078 tranches: an upfront (ONE_TIME frequency) rental is paid in
     * consecutive groups of this many monthly billing periods — ≤ 12 monthly
     * entries collapse into a single whole-rental payment, longer rentals
     * split into yearly tranches (12 + 12 + … + rest).
     */
    public const int MONTHS_PER_UPFRONT_TRANCHE = 12;

    private const int DAYS_PER_WEEK = 7;
    private const int DAYS_PER_MONTH = 30;
    private const int DAYS_PER_YEAR = 365;

    /**
     * Legal maximum for any single recurring (ON_DEMAND) GoPay charge, in
     * halere (CZK × 100). It is **disclosed to the customer** in the
     * dedicated recurring-payment consent block at checkout, in the
     * confirmation e-mail sent within 2 working days, in the 7-business-day
     * advance notice and in the public Podmínky opakovaných plateb partial
     * — Podmínky opakovaných plateb čl. III states verbatim:
     *
     *     „Maximální částka opakované platby činí: 15 000 Kč."
     *
     * Source of truth: `public/documents/podminky-opakovanych-plateb.pdf`.
     * Changing this value in code WITHOUT first re-issuing the Podmínky PDF
     * (and notifying customers per čl. V at least 7 working days in
     * advance) is a compliance breach — see .claude/COMPLIANCE.md
     * (Recurring payments).
     *
     * Exposed to Twig via the `recurring_payment_legal_max_in_czk` global
     * (see config/packages/twig.php) and explicitly via controller context
     * on order-accept + recurring-payment e-mails.
     */
    public const int MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER = 1_500_000;

    /**
     * @return int Total price in halire
     */
    public function calculatePrice(
        StorageType $storageType,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): int {
        if (null === $endDate) {
            return $storageType->defaultPricePerMonthLongTerm;
        }

        $days = $this->calculateDays($startDate, $endDate);

        if ($days <= 0) {
            return 0;
        }

        if ($days < self::WEEKLY_THRESHOLD_DAYS) {
            return $this->calculateWeeklyPrice($storageType->defaultPricePerWeek, $days);
        }

        $monthlyRate = $days < self::SHORT_TERM_THRESHOLD_DAYS
            ? $storageType->defaultPricePerMonth
            : $storageType->defaultPricePerMonthLongTerm;

        return $this->calculateMonthlyPrice($monthlyRate, $days);
    }

    /**
     * @return int Total price in halire
     */
    public function calculatePriceForStorage(
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): int {
        if (null === $endDate) {
            return $storage->getEffectivePricePerMonthLongTerm();
        }

        $days = $this->calculateDays($startDate, $endDate);

        if ($days <= 0) {
            return 0;
        }

        if ($days < self::WEEKLY_THRESHOLD_DAYS) {
            return $this->calculateWeeklyPrice($storage->getEffectivePricePerWeek(), $days);
        }

        $monthlyRate = $days < self::SHORT_TERM_THRESHOLD_DAYS
            ? $storage->getEffectivePricePerMonth()
            : $storage->getEffectivePricePerMonthLongTerm();

        return $this->calculateMonthlyPrice($monthlyRate, $days);
    }

    /**
     * Calculate price using weekly rate (for rentals < 31 days).
     */
    private function calculateWeeklyPrice(int $weeklyRate, int $days): int
    {
        $fullWeeks = intdiv($days, self::DAYS_PER_WEEK);
        $remainingDays = $days % self::DAYS_PER_WEEK;

        $weeklyTotal = $fullWeeks * $weeklyRate;
        $remainingTotal = self::roundUpToWholeCzk($remainingDays * $weeklyRate / self::DAYS_PER_WEEK);

        return $weeklyTotal + $remainingTotal;
    }

    /**
     * Calculate price using monthly rate (for rentals >= 31 days).
     */
    private function calculateMonthlyPrice(int $monthlyRate, int $days): int
    {
        $fullMonths = intdiv($days, self::DAYS_PER_MONTH);
        $remainingDays = $days % self::DAYS_PER_MONTH;

        $monthlyTotal = $fullMonths * $monthlyRate;
        $remainingTotal = self::roundUpToWholeCzk($remainingDays * $monthlyRate / self::DAYS_PER_MONTH);

        return $monthlyTotal + $remainingTotal;
    }

    /**
     * Round a haler amount UP to the nearest whole CZK (multiple of 100 halere).
     *
     * Why: every customer-facing surface — step 1 of the order flow, the
     * recap, the GoPay charge — must show the same number. The step-1 sidebar
     * formats with `number_format(0)`, so a prorated 1 428,57 Kč there reads
     * as "1 429 Kč" while step 2 / GoPay see 1 428,57. Rounding the prorated
     * tail UP to whole CZK at calc time keeps every surface in lockstep and
     * never charges less than what was displayed.
     */
    public static function roundUpToWholeCzk(float $amountInHaler): int
    {
        return (int) ceil($amountInHaler / 100) * 100;
    }

    /**
     * Calculate number of days between two dates (inclusive of start, exclusive of end).
     */
    private function calculateDays(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate): int
    {
        $interval = $startDate->diff($endDate);

        // DateTimeImmutable::diff() always produces a valid interval with days
        return (int) $interval->days;
    }

    /**
     * @return int Price in halire
     */
    public function calculateFirstPaymentPrice(
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        PaymentFrequency $frequency = PaymentFrequency::MONTHLY,
    ): int {
        $schedule = $this->buildPaymentSchedule($storage, $startDate, $endDate, $frequency);

        return $schedule->isEmpty() ? 0 : $schedule->firstPayment()->amount;
    }

    /**
     * @return 'weekly'|'monthly_short'|'monthly_long'|'yearly'
     */
    public function resolveRateType(
        PaymentFrequency $frequency,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): string {
        if (PaymentFrequency::YEARLY === $frequency) {
            return 'yearly';
        }

        if (null === $endDate) {
            return 'monthly_long';
        }

        $days = $this->calculateDays($startDate, $endDate);

        if ($days < self::WEEKLY_THRESHOLD_DAYS) {
            return 'weekly';
        }

        return $days < self::SHORT_TERM_THRESHOLD_DAYS ? 'monthly_short' : 'monthly_long';
    }

    /**
     * Whether a rental is eligible for the YEARLY frequency choice — at least
     * {@see self::YEARLY_THRESHOLD_DAYS} long. Null end (legacy open-ended
     * orders) counts as eligible.
     */
    public function isEligibleForYearly(\DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): bool
    {
        if (null === $endDate) {
            return true;
        }

        return $this->calculateDays($startDate, $endDate) >= self::YEARLY_THRESHOLD_DAYS;
    }

    /**
     * Check if a rental duration requires recurring billing.
     */
    public function needsRecurringBilling(\DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): bool
    {
        if (null === $endDate) {
            return true; // UNLIMITED always needs recurring
        }

        return $this->calculateDays($startDate, $endDate) >= self::WEEKLY_THRESHOLD_DAYS;
    }

    /**
     * Build the authoritative list of charges the customer will see.
     *
     * Mirrors the cadence used by `ChargeRecurringPaymentHandler` (calendar
     * months via `\DateTimeImmutable::modify('+1 month')`, with a prorated
     * tail when the next full month would overshoot the contract end). The
     * order_create / order_accept / order_payment surfaces all show the same
     * schedule; the cron later replays it. Any divergence here breaks the
     * "no surprises" promise — keep this method and the cron in sync.
     */
    public function buildPaymentSchedule(
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        PaymentFrequency $frequency = PaymentFrequency::MONTHLY,
    ): PaymentSchedule {
        $weeklyRate = $storage->getEffectivePricePerWeek();

        if (PaymentFrequency::YEARLY === $frequency) {
            $yearlyRate = $storage->getEffectivePricePerYear();

            // UNLIMITED yearly: open-ended yearly recurrence — only the first
            // charge is on the schedule, the rest are added by the manual cron
            // after each successful billing cycle.
            if (null === $endDate) {
                return new PaymentSchedule(
                    entries: [new PaymentScheduleEntry($startDate, $yearlyRate)],
                    isRecurring: true,
                    isOpenEnded: true,
                    monthlyAmount: null,
                    yearlyAmount: $yearlyRate,
                );
            }

            return new PaymentSchedule(
                entries: $this->walkYearsFromAnchor($yearlyRate, $startDate, $endDate),
                isRecurring: true,
                isOpenEnded: false,
                monthlyAmount: null,
                yearlyAmount: $yearlyRate,
            );
        }

        if (null === $endDate) {
            $monthlyRate = $storage->getEffectivePricePerMonthLongTerm();

            return new PaymentSchedule(
                entries: [new PaymentScheduleEntry($startDate, $monthlyRate)],
                isRecurring: true,
                isOpenEnded: true,
                monthlyAmount: $monthlyRate,
            );
        }

        $days = $this->calculateDays($startDate, $endDate);

        if ($days <= 0) {
            return new PaymentSchedule(
                entries: [],
                isRecurring: false,
                isOpenEnded: false,
                monthlyAmount: null,
            );
        }

        if ($days < self::WEEKLY_THRESHOLD_DAYS) {
            return new PaymentSchedule(
                entries: [new PaymentScheduleEntry($startDate, $this->calculateWeeklyPrice($weeklyRate, $days))],
                isRecurring: false,
                isOpenEnded: false,
                monthlyAmount: null,
            );
        }

        $monthlyRate = $days < self::SHORT_TERM_THRESHOLD_DAYS
            ? $storage->getEffectivePricePerMonth()
            : $storage->getEffectivePricePerMonthLongTerm();

        // Spec 078: rental paid upfront by bank transfer. The tranche amounts
        // are the EXACT sums the MANUAL monthly track would collect (same
        // duration tier, same prorated tail) — no discount. Short rentals
        // (< 31 days) never reach this branch: they keep weekly pricing above.
        if (PaymentFrequency::ONE_TIME === $frequency) {
            return $this->buildUpfrontSchedule($monthlyRate, $startDate, $endDate);
        }

        return new PaymentSchedule(
            entries: $this->walkMonthsFromAnchor($monthlyRate, $startDate, $endDate),
            isRecurring: true,
            isOpenEnded: false,
            monthlyAmount: $monthlyRate,
        );
    }

    /**
     * Build the locked-in payment schedule for an *existing* order, using
     * Order.firstPaymentPrice as the monthly anchor (NOT the current Storage price).
     *
     * Why a separate method: buildPaymentSchedule(Storage, ...) reads the
     * *current* Storage price. After an order is placed the storage price
     * may change; the order's monthly stays. For displaying a schedule on
     * portal/admin/landlord detail pages we must respect that lock.
     */
    public function buildScheduleFromOrder(Order $order): PaymentSchedule
    {
        $isYearly = PaymentFrequency::YEARLY === $order->paymentFrequency;

        if ($isYearly) {
            $yearlyRate = $order->firstPaymentPrice;

            if ($order->isOpenEnded()) {
                return new PaymentSchedule(
                    entries: [new PaymentScheduleEntry($order->startDate, $yearlyRate)],
                    isRecurring: true,
                    isOpenEnded: true,
                    monthlyAmount: null,
                    yearlyAmount: $yearlyRate,
                );
            }

            $endDate = $order->endDate;
            \assert(null !== $endDate);

            return new PaymentSchedule(
                entries: $this->walkYearsFromAnchor($yearlyRate, $order->startDate, $endDate),
                isRecurring: true,
                isOpenEnded: false,
                monthlyAmount: null,
                yearlyAmount: $yearlyRate,
            );
        }

        // Spec 078 upfront orders: for ≤ 12 monthly periods firstPaymentPrice
        // is the WHOLE rental total (single payment) — the monthly-walk branch
        // below would corrupt it. Longer rentals pay in yearly tranches: the
        // first tranche is always 12 FULL months (the prorated tail can only
        // sit in the last tranche), so the locked monthly rate is recoverable
        // exactly as firstPaymentPrice / 12 and the partition rebuilds 1:1.
        if (PaymentFrequency::ONE_TIME === $order->paymentFrequency) {
            $endDate = $order->endDate;

            if (null !== $endDate && self::countMonthlyWalkEntries($order->startDate, $endDate) > self::MONTHS_PER_UPFRONT_TRANCHE) {
                return $this->buildUpfrontSchedule(
                    $order->getUpfrontLockedMonthlyRate(),
                    $order->startDate,
                    $endDate,
                );
            }

            return new PaymentSchedule(
                entries: [new PaymentScheduleEntry($order->startDate, $order->firstPaymentPrice)],
                isRecurring: false,
                isOpenEnded: false,
                monthlyAmount: null,
            );
        }

        if ($order->isOpenEnded()) {
            return new PaymentSchedule(
                entries: [new PaymentScheduleEntry($order->startDate, $order->firstPaymentPrice)],
                isRecurring: true,
                isOpenEnded: true,
                monthlyAmount: $order->firstPaymentPrice,
            );
        }

        $endDate = $order->endDate;
        \assert(null !== $endDate);

        $days = $this->calculateDays($order->startDate, $endDate);

        if ($days < self::WEEKLY_THRESHOLD_DAYS) {
            return new PaymentSchedule(
                entries: [new PaymentScheduleEntry($order->startDate, $order->firstPaymentPrice)],
                isRecurring: false,
                isOpenEnded: false,
                monthlyAmount: null,
            );
        }

        $monthlyRate = $order->firstPaymentPrice;

        return new PaymentSchedule(
            entries: $this->walkMonthsFromAnchor($monthlyRate, $order->startDate, $endDate),
            isRecurring: true,
            isOpenEnded: false,
            monthlyAmount: $monthlyRate,
        );
    }

    /**
     * Walk calendar months from start to end at a fixed monthly rate, charging
     * a full month whenever the next month boundary still fits, then prorating
     * the trailing partial period at the 30-day daily rate.
     *
     * Mirrors `ChargeRecurringPaymentHandler::calculateBillingAmount`. The
     * "no surprises" promise — order_create / order_accept / order_payment
     * surfaces, the post-creation order detail, and the recurring-billing
     * cron all walk identically. Any divergence here breaks that promise.
     *
     * @return list<PaymentScheduleEntry>
     */
    private function walkMonthsFromAnchor(int $monthlyRate, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate): array
    {
        $entries = [];
        $billingDate = $startDate;
        while ($billingDate < $endDate) {
            $nextBillingDate = $billingDate->modify('+1 month');
            if ($nextBillingDate <= $endDate) {
                $entries[] = new PaymentScheduleEntry($billingDate, $monthlyRate);
                $billingDate = $nextBillingDate;

                continue;
            }
            $remainingDays = max(1, $this->calculateDays($billingDate, $endDate));
            $proratedAmount = max(100, self::roundUpToWholeCzk($remainingDays * $monthlyRate / self::DAYS_PER_MONTH));
            $entries[] = new PaymentScheduleEntry($billingDate, $proratedAmount);

            break;
        }

        return $entries;
    }

    /**
     * Same walking logic as {@see self::walkMonthsFromAnchor()}, but cadence
     * is `+1 year` and tail proration uses a 365-day denominator. Used for
     * fixed-term YEARLY schedules (LIMITED rentals ≥ {@see self::YEARLY_THRESHOLD_DAYS}).
     *
     * @return list<PaymentScheduleEntry>
     */
    private function walkYearsFromAnchor(int $yearlyRate, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate): array
    {
        $entries = [];
        $billingDate = $startDate;
        while ($billingDate < $endDate) {
            $nextBillingDate = $billingDate->modify('+1 year');
            if ($nextBillingDate <= $endDate) {
                $entries[] = new PaymentScheduleEntry($billingDate, $yearlyRate);
                $billingDate = $nextBillingDate;

                continue;
            }
            $remainingDays = max(1, $this->calculateDays($billingDate, $endDate));
            $proratedAmount = max(100, self::roundUpToWholeCzk($remainingDays * $yearlyRate / self::DAYS_PER_YEAR));
            $entries[] = new PaymentScheduleEntry($billingDate, $proratedAmount);

            break;
        }

        return $entries;
    }

    /**
     * Upfront (ONE_TIME) schedule — the monthly walk partitioned into
     * consecutive groups of {@see self::MONTHS_PER_UPFRONT_TRANCHE} entries;
     * each group becomes ONE payment (sum of its entries) dated at the group's
     * first entry. ≤ 12 monthly periods therefore stay a single whole-rental
     * payment. Reusing the monthly walk means duration tier and prorated tail
     * are inherited by construction — never re-derive proration here.
     *
     * monthlyAmount carries the equivalence rate the totals were built from
     * (drives the "odpovídá X Kč / měsíc" note).
     */
    private function buildUpfrontSchedule(int $monthlyRate, \DateTimeImmutable $startDate, \DateTimeImmutable $endDate): PaymentSchedule
    {
        $tranches = [];
        foreach (array_chunk($this->walkMonthsFromAnchor($monthlyRate, $startDate, $endDate), self::MONTHS_PER_UPFRONT_TRANCHE) as $group) {
            $tranches[] = new PaymentScheduleEntry(
                $group[0]->chargeDate,
                array_sum(array_map(static fn (PaymentScheduleEntry $e): int => $e->amount, $group)),
            );
        }

        return new PaymentSchedule(
            entries: $tranches,
            isRecurring: false,
            isOpenEnded: false,
            monthlyAmount: $monthlyRate,
        );
    }

    /**
     * Amount in halere of the single upfront tranche starting at $trancheStart:
     * the next up-to-12 entries of the monthly walk towards $endDate (full
     * months at the monthly rate, prorated tail included). Billing counterpart
     * of {@see self::buildUpfrontSchedule()} — RecurringAmountCalculator uses
     * it for ONE_TIME contracts with a billing anchor (spec 078 tranches).
     */
    public function calculateUpfrontTrancheAmount(int $monthlyRate, \DateTimeImmutable $trancheStart, \DateTimeImmutable $endDate): int
    {
        $entries = array_slice(
            $this->walkMonthsFromAnchor($monthlyRate, $trancheStart, $endDate),
            0,
            self::MONTHS_PER_UPFRONT_TRANCHE,
        );

        return max(100, array_sum(array_map(static fn (PaymentScheduleEntry $e): int => $e->amount, $entries)));
    }

    /**
     * Whether an upfront (ONE_TIME) payment for this window splits into yearly
     * tranches (spec 078) — i.e. more monthly billing periods than fit in one
     * tranche. Date-only, so form surfaces can phrase the upfront option
     * correctly before a storage unit is even selected. Static so validation
     * callbacks without DI (AdminOnboardingFormData) can share the rule.
     */
    public static function isUpfrontSplitIntoTranches(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate): bool
    {
        return self::countMonthlyWalkEntries($startDate, $endDate) > self::MONTHS_PER_UPFRONT_TRANCHE;
    }

    /**
     * Number of entries {@see self::walkMonthsFromAnchor()} would produce for
     * the window — full months plus one prorated tail. Depends only on the
     * dates, so callers can decide on the tranche split before knowing a rate.
     */
    private static function countMonthlyWalkEntries(\DateTimeImmutable $startDate, \DateTimeImmutable $endDate): int
    {
        $count = 0;
        $billingDate = $startDate;
        while ($billingDate < $endDate) {
            ++$count;
            $nextBillingDate = $billingDate->modify('+1 month');
            if ($nextBillingDate > $endDate) {
                break;
            }
            $billingDate = $nextBillingDate;
        }

        return $count;
    }

    /**
     * @return array{
     *     days: int,
     *     rate_type: 'weekly'|'monthly_short'|'monthly_long',
     *     full_periods: int,
     *     remaining_days: int,
     *     period_price: int,
     *     remaining_price: int,
     *     total_price: int
     * }
     */
    public function getPriceBreakdown(
        StorageType $storageType,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): array {
        if (null === $endDate) {
            return [
                'days' => 0,
                'rate_type' => 'monthly_long',
                'full_periods' => 1,
                'remaining_days' => 0,
                'period_price' => $storageType->defaultPricePerMonthLongTerm,
                'remaining_price' => 0,
                'total_price' => $storageType->defaultPricePerMonthLongTerm,
            ];
        }

        $days = $this->calculateDays($startDate, $endDate);

        if ($days < self::WEEKLY_THRESHOLD_DAYS) {
            $fullWeeks = intdiv($days, self::DAYS_PER_WEEK);
            $remainingDays = $days % self::DAYS_PER_WEEK;
            $weeklyRate = $storageType->defaultPricePerWeek;
            $periodPrice = $fullWeeks * $weeklyRate;
            $remainingPrice = self::roundUpToWholeCzk($remainingDays * $weeklyRate / self::DAYS_PER_WEEK);

            return [
                'days' => $days,
                'rate_type' => 'weekly',
                'full_periods' => $fullWeeks,
                'remaining_days' => $remainingDays,
                'period_price' => $periodPrice,
                'remaining_price' => $remainingPrice,
                'total_price' => $periodPrice + $remainingPrice,
            ];
        }

        $monthlyRate = $days < self::SHORT_TERM_THRESHOLD_DAYS
            ? $storageType->defaultPricePerMonth
            : $storageType->defaultPricePerMonthLongTerm;
        $rateType = $days < self::SHORT_TERM_THRESHOLD_DAYS ? 'monthly_short' : 'monthly_long';

        $fullMonths = intdiv($days, self::DAYS_PER_MONTH);
        $remainingDays = $days % self::DAYS_PER_MONTH;
        $periodPrice = $fullMonths * $monthlyRate;
        $remainingPrice = self::roundUpToWholeCzk($remainingDays * $monthlyRate / self::DAYS_PER_MONTH);

        return [
            'days' => $days,
            'rate_type' => $rateType,
            'full_periods' => $fullMonths,
            'remaining_days' => $remainingDays,
            'period_price' => $periodPrice,
            'remaining_price' => $remainingPrice,
            'total_price' => $periodPrice + $remainingPrice,
        ];
    }
}
