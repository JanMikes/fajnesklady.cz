<?php

declare(strict_types=1);

namespace App\Service\Billing;

/**
 * The ONE place that adds calendar months to a billing date.
 *
 * PHP's `modify('+1 month')` overflows past short months: 31.08 + 1 month is
 * 01.10, and the anchor then sits on the 1st for good. Every billing period
 * after that is shifted, and the customer silently gets the overflow days
 * free (order 019f98cd: 31.08 → 01.10).
 *
 * Instead, months are added like card billing does it: keep the anchor day,
 * clamp to the last day of a shorter month, and return to the anchor day as
 * soon as the month is long enough — 31.08 → 30.09 → 31.10 → 30.11 → 31.12.
 * The time of day is preserved.
 */
final class BillingCalendar
{
    /**
     * @param int|null $anchorDay the day of month the schedule is anchored to
     *                            (1–31); null = the day of $from
     */
    public static function addMonths(\DateTimeImmutable $from, int $months, ?int $anchorDay = null): \DateTimeImmutable
    {
        $day = $anchorDay ?? (int) $from->format('j');

        $monthIndex = (int) $from->format('Y') * 12 + ((int) $from->format('n') - 1) + $months;
        $year = intdiv($monthIndex, 12);
        $month = $monthIndex % 12 + 1;

        $daysInMonth = (int) $from->setDate($year, $month, 1)->format('t');

        return $from->setDate($year, $month, min($day, $daysInMonth));
    }

    /**
     * Whether $date is a month-end clamp of $anchorDay — i.e. the anchor day
     * does not exist in that month and $date is the month's last day.
     */
    public static function isClampOf(\DateTimeImmutable $date, int $anchorDay): bool
    {
        $day = (int) $date->format('j');

        return $day < $anchorDay && $day === (int) $date->format('t');
    }
}
