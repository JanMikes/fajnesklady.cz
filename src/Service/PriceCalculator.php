<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\StorageType;

final readonly class PriceCalculator
{
    private const int WEEKLY_THRESHOLD_DAYS = 28;
    private const int DAYS_PER_WEEK = 7;
    private const int DAYS_PER_MONTH = 30;

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
            return $storageType->pricePerMonth;
        }

        $days = $this->calculateDays($startDate, $endDate);

        if ($days <= 0) {
            return 0;
        }

        if ($days < self::WEEKLY_THRESHOLD_DAYS) {
            return $this->calculateWeeklyPrice($storageType->pricePerWeek, $days);
        }

        return $this->calculateMonthlyPrice($storageType->pricePerMonth, $days);
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
                'period_price' => $storageType->pricePerMonth,
                'remaining_price' => 0,
                'total_price' => $storageType->pricePerMonth,
            ];
        }

        $days = $this->calculateDays($startDate, $endDate);

        if ($days < self::WEEKLY_THRESHOLD_DAYS) {
            $fullWeeks = intdiv($days, self::DAYS_PER_WEEK);
            $remainingDays = $days % self::DAYS_PER_WEEK;
            $weeklyRate = $storageType->pricePerWeek;
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
        $monthlyRate = $storageType->pricePerMonth;
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
