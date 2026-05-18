<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Service\Billing\ManualBillingReminderSchedule;
use PHPUnit\Framework\TestCase;

final class ManualBillingReminderScheduleTest extends TestCase
{
    public function testStagesReturnsAllFiveStagesWithTheirOffsets(): void
    {
        $schedule = new ManualBillingReminderSchedule(-7, -2, 0, 3, 7);

        self::assertSame([
            'initial' => -7,
            'd_minus_2' => -2,
            'd_zero' => 0,
            'd_plus_3' => 3,
            'd_plus_7' => 7,
        ], $schedule->stages());
    }

    public function testDueStageOnReturnsInitialWhenTodayIsSevenDaysBeforeAnchor(): void
    {
        $schedule = new ManualBillingReminderSchedule(-7, -2, 0, 3, 7);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $anchor = new \DateTimeImmutable('2025-06-22');

        self::assertSame('initial', $schedule->dueStageOn($now, $anchor));
    }

    public function testDueStageOnReturnsReminderWhenTodayIsTwoDaysBeforeAnchor(): void
    {
        $schedule = new ManualBillingReminderSchedule(-7, -2, 0, 3, 7);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $anchor = new \DateTimeImmutable('2025-06-17');

        self::assertSame('d_minus_2', $schedule->dueStageOn($now, $anchor));
    }

    public function testDueStageOnReturnsFinalDueWhenTodayEqualsAnchor(): void
    {
        $schedule = new ManualBillingReminderSchedule(-7, -2, 0, 3, 7);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $anchor = new \DateTimeImmutable('2025-06-15');

        self::assertSame('d_zero', $schedule->dueStageOn($now, $anchor));
    }

    public function testDueStageOnReturnsOverdueFirstWhenTodayIsThreeDaysAfterAnchor(): void
    {
        $schedule = new ManualBillingReminderSchedule(-7, -2, 0, 3, 7);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $anchor = new \DateTimeImmutable('2025-06-12');

        self::assertSame('d_plus_3', $schedule->dueStageOn($now, $anchor));
    }

    public function testDueStageOnReturnsOverdueFinalWhenTodayIsSevenDaysAfterAnchor(): void
    {
        $schedule = new ManualBillingReminderSchedule(-7, -2, 0, 3, 7);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $anchor = new \DateTimeImmutable('2025-06-08');

        self::assertSame('d_plus_7', $schedule->dueStageOn($now, $anchor));
    }

    public function testDueStageOnReturnsNullWhenNoStageMatches(): void
    {
        $schedule = new ManualBillingReminderSchedule(-7, -2, 0, 3, 7);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $anchor = new \DateTimeImmutable('2025-06-20');

        self::assertNull($schedule->dueStageOn($now, $anchor));
    }

    public function testDueStageOnIgnoresTimeOfDay(): void
    {
        $schedule = new ManualBillingReminderSchedule(-7, -2, 0, 3, 7);
        $earlyMorning = new \DateTimeImmutable('2025-06-15 00:30:00');
        $lateEvening = new \DateTimeImmutable('2025-06-15 23:45:00');
        $anchor = new \DateTimeImmutable('2025-06-22');

        self::assertSame('initial', $schedule->dueStageOn($earlyMorning, $anchor));
        self::assertSame('initial', $schedule->dueStageOn($lateEvening, $anchor));
    }

    public function testCustomScheduleHonoursPerPlaceOffsets(): void
    {
        $schedule = new ManualBillingReminderSchedule(-14, -5, 0, 1, 14);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        self::assertSame('initial', $schedule->dueStageOn($now, new \DateTimeImmutable('2025-06-29')));
        self::assertSame('d_minus_2', $schedule->dueStageOn($now, new \DateTimeImmutable('2025-06-20')));
        self::assertNull($schedule->dueStageOn($now, new \DateTimeImmutable('2025-06-13')));
    }

    public function testOffsetBoundsReturnsMinAndMax(): void
    {
        $schedule = new ManualBillingReminderSchedule(-7, -2, 0, 3, 7);

        self::assertSame([-7, 7], $schedule->offsetBounds());
    }
}
