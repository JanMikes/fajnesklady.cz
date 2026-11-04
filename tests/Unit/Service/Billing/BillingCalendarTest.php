<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Service\Billing\BillingCalendar;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingCalendarTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, ?int, string}>
     */
    public static function cases(): iterable
    {
        // The incident: PHP's modify('+1 month') turned 31.08 into 01.10.
        yield '31.08 clamps to 30.09' => ['2026-08-31', 1, null, '2026-09-30'];
        yield 'anchor 31 returns to 31.10' => ['2026-09-30', 1, 31, '2026-10-31'];
        yield '31.01 clamps to 28.02' => ['2027-01-31', 1, null, '2027-02-28'];
        yield '31.01 clamps to 29.02 in a leap year' => ['2028-01-31', 1, null, '2028-02-29'];
        yield 'anchor 30 after February returns to 30.03' => ['2027-02-28', 1, 30, '2027-03-30'];
        yield 'ordinary day unchanged' => ['2026-11-25', 1, null, '2026-12-25'];
        yield 'year rollover' => ['2026-12-31', 1, null, '2027-01-31'];
        yield '12 months from 29.02 clamps to 28.02' => ['2028-02-29', 12, null, '2029-02-28'];
        yield '12 months with anchor 29 back to leap day' => ['2031-02-28', 12, 29, '2032-02-29'];
        yield '12 months in one jump keeps the day' => ['2026-01-31', 12, null, '2027-01-31'];
    }

    #[DataProvider('cases')]
    public function testAddMonths(string $from, int $months, ?int $anchorDay, string $expected): void
    {
        $result = BillingCalendar::addMonths(new \DateTimeImmutable($from), $months, $anchorDay);

        self::assertSame($expected, $result->format('Y-m-d'));
    }

    public function testTimeOfDayIsPreserved(): void
    {
        $result = BillingCalendar::addMonths(new \DateTimeImmutable('2026-08-31 15:15:48'), 1);

        self::assertSame('2026-09-30 15:15:48', $result->format('Y-m-d H:i:s'));
    }

    public function testWalkingTwelveMonthsFromThe31stNeverDrifts(): void
    {
        $date = new \DateTimeImmutable('2026-08-31');
        $days = [];
        for ($i = 0; $i < 12; ++$i) {
            $date = BillingCalendar::addMonths($date, 1, 31);
            $days[] = $date->format('d.m');
        }

        self::assertSame(['30.09', '31.10', '30.11', '31.12', '31.01', '28.02', '31.03', '30.04', '31.05', '30.06', '31.07', '31.08'], $days);
    }

    public function testIsClampOf(): void
    {
        self::assertTrue(BillingCalendar::isClampOf(new \DateTimeImmutable('2026-09-30'), 31));
        self::assertTrue(BillingCalendar::isClampOf(new \DateTimeImmutable('2027-02-28'), 30));
        self::assertFalse(BillingCalendar::isClampOf(new \DateTimeImmutable('2026-10-30'), 31), '30.10 is not the last day of October.');
        self::assertFalse(BillingCalendar::isClampOf(new \DateTimeImmutable('2026-09-30'), 30));
    }
}
