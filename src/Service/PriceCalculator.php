<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Storage;
use App\Entity\StorageType;

final readonly class PriceCalculator
{
    private const int WEEKLY_THRESHOLD_DAYS = 28;
    private const int DAYS_PER_WEEK = 7;
    private const int DAYS_PER_MONTH = 30;

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
     * Calculate total rental price based on duration.
     *
     * Rule: Duration < 28 days uses weekly rate, >= 28 days uses monthly rate.
     * Prices are in halire (cents).
     *
     * @return int Total price in halire
     */
    public function calculatePrice(
        StorageType $storageType,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): int {
        // For unlimited rentals, return first period price (monthly or yearly based on frequency)
        if (null === $endDate) {
            return $storageType->defaultPricePerMonth;
        }

        $days = $this->calculateDays($startDate, $endDate);

        if ($days <= 0) {
            return 0;
        }

        if ($days < self::WEEKLY_THRESHOLD_DAYS) {
            return $this->calculateWeeklyPrice($storageType->defaultPricePerWeek, $days);
        }

        return $this->calculateMonthlyPrice($storageType->defaultPricePerMonth, $days);
    }

    /**
     * Calculate total rental price for a specific storage (uses effective prices).
     *
     * @return int Total price in halire
     */
    public function calculatePriceForStorage(
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): int {
        $pricePerWeek = $storage->getEffectivePricePerWeek();
        $pricePerMonth = $storage->getEffectivePricePerMonth();

        // For unlimited rentals, return first period price (monthly)
        if (null === $endDate) {
            return $pricePerMonth;
        }

        $days = $this->calculateDays($startDate, $endDate);

        if ($days <= 0) {
            return 0;
        }

        if ($days < self::WEEKLY_THRESHOLD_DAYS) {
            return $this->calculateWeeklyPrice($pricePerWeek, $days);
        }

        return $this->calculateMonthlyPrice($pricePerMonth, $days);
    }

    /**
     * Calculate price using weekly rate (for rentals < 28 days).
     */
    private function calculateWeeklyPrice(int $weeklyRate, int $days): int
    {
        $fullWeeks = intdiv($days, self::DAYS_PER_WEEK);
        $remainingDays = $days % self::DAYS_PER_WEEK;

        $weeklyTotal = $fullWeeks * $weeklyRate;
        $dailyRate = $weeklyRate / self::DAYS_PER_WEEK;
        $remainingTotal = (int) round($remainingDays * $dailyRate);

        return $weeklyTotal + $remainingTotal;
    }

    /**
     * Calculate price using monthly rate (for rentals >= 28 days).
     */
    private function calculateMonthlyPrice(int $monthlyRate, int $days): int
    {
        $fullMonths = intdiv($days, self::DAYS_PER_MONTH);
        $remainingDays = $days % self::DAYS_PER_MONTH;

        $monthlyTotal = $fullMonths * $monthlyRate;
        $dailyRate = $monthlyRate / self::DAYS_PER_MONTH;
        $remainingTotal = (int) round($remainingDays * $dailyRate);

        return $monthlyTotal + $remainingTotal;
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
     * Calculate the first payment price for an order.
     *
     * For rentals < 28 days: full price (no recurring).
     * For rentals >= 28 days or unlimited: first month's price.
     *
     * @return int Price in halire
     */
    public function calculateFirstPaymentPrice(
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
    ): int {
        // UNLIMITED: first month
        if (null === $endDate) {
            return $storage->getEffectivePricePerMonth();
        }

        $days = $this->calculateDays($startDate, $endDate);

        // Short rental: full price, no recurring
        if ($days < self::WEEKLY_THRESHOLD_DAYS) {
            return $this->calculatePriceForStorage($storage, $startDate, $endDate);
        }

        // LIMITED >= 1 month: first month price
        return $storage->getEffectivePricePerMonth();
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
     * Get price breakdown for display purposes.
     *
     * @return array{
     *     days: int,
     *     rate_type: 'weekly'|'monthly',
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
                'rate_type' => 'monthly',
                'full_periods' => 1,
                'remaining_days' => 0,
                'period_price' => $storageType->defaultPricePerMonth,
                'remaining_price' => 0,
                'total_price' => $storageType->defaultPricePerMonth,
            ];
        }

        $days = $this->calculateDays($startDate, $endDate);

        if ($days < self::WEEKLY_THRESHOLD_DAYS) {
            $fullWeeks = intdiv($days, self::DAYS_PER_WEEK);
            $remainingDays = $days % self::DAYS_PER_WEEK;
            $weeklyRate = $storageType->defaultPricePerWeek;
            $periodPrice = $fullWeeks * $weeklyRate;
            $dailyRate = $weeklyRate / self::DAYS_PER_WEEK;
            $remainingPrice = (int) round($remainingDays * $dailyRate);

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

        $fullMonths = intdiv($days, self::DAYS_PER_MONTH);
        $remainingDays = $days % self::DAYS_PER_MONTH;
        $monthlyRate = $storageType->defaultPricePerMonth;
        $periodPrice = $fullMonths * $monthlyRate;
        $dailyRate = $monthlyRate / self::DAYS_PER_MONTH;
        $remainingPrice = (int) round($remainingDays * $dailyRate);

        return [
            'days' => $days,
            'rate_type' => 'monthly',
            'full_periods' => $fullMonths,
            'remaining_days' => $remainingDays,
            'period_price' => $periodPrice,
            'remaining_price' => $remainingPrice,
            'total_price' => $periodPrice + $remainingPrice,
        ];
    }
}
