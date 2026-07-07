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

    public function testDueStageOnReturnsNullBeforeTheEarliestOffsetDay(): void
    {
        $schedule = new ManualBillingReminderSchedule(-7, -2, 0, 3, 7);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $anchor = new \DateTimeImmutable('2025-06-25');

        // d-10 is before the initial (-7) offset — nothing is due yet.
        self::assertNull($schedule->dueStageOn($now, $anchor));
    }

    public function testDueStageOnStaysOnCurrentBracketBetweenStageDays(): void
    {
        $schedule = new ManualBillingReminderSchedule(-7, -2, 0, 3, 7);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        // Between two stage days the CURRENT bracket's stage is returned (the
        // handler's sentStages gate makes the repeat dispatch a no-op); a
        // contract entering the track late catches up with exactly this one
        // stage instead of silently skipping it.
        self::assertSame('initial', $schedule->dueStageOn($now, new \DateTimeImmutable('2025-06-20'))); // d-5
        self::assertSame('d_minus_2', $schedule->dueStageOn($now, new \DateTimeImmutable('2025-06-16'))); // d-1
        self::assertSame('d_zero', $schedule->dueStageOn($now, new \DateTimeImmutable('2025-06-14'))); // d+1
        self::assertSame('d_plus_3', $schedule->dueStageOn($now, new \DateTimeImmutable('2025-06-11'))); // d+4
        self::assertSame('d_plus_7', $schedule->dueStageOn($now, new \DateTimeImmutable('2025-06-05'))); // d+10
        self::assertSame('d_plus_7', $schedule->dueStageOn($now, new \DateTimeImmutable('2025-05-01'))); // d+45
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
        // d+2 sits in the overdue-first bracket (offset +1) of this schedule.
        self::assertSame('d_plus_3', $schedule->dueStageOn($now, new \DateTimeImmutable('2025-06-13')));
        // d-15 is before the earliest (-14) offset — dormant.
        self::assertNull($schedule->dueStageOn($now, new \DateTimeImmutable('2025-06-30')));
    }

    public function testOffsetBoundsReturnsMinAndMax(): void
    {
        $schedule = new ManualBillingReminderSchedule(-7, -2, 0, 3, 7);

        self::assertSame([-7, 7], $schedule->offsetBounds());
    }
}
