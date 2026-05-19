<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Onboarding;

use App\Service\Onboarding\OnboardingReminderSchedule;
use PHPUnit\Framework\TestCase;

class OnboardingReminderScheduleTest extends TestCase
{
    public function testDPlus2HitsTwoCalendarDaysAfterSigning(): void
    {
        $signedAt = new \DateTimeImmutable('2026-05-10 14:32:00');
        $now = new \DateTimeImmutable('2026-05-12 09:00:00');

        $this->assertSame(
            OnboardingReminderSchedule::STAGE_D_PLUS_2,
            OnboardingReminderSchedule::stageDueOn($now, $signedAt),
        );
    }

    public function testDPlus5HitsFiveCalendarDaysAfterSigning(): void
    {
        $signedAt = new \DateTimeImmutable('2026-05-10 14:32:00');
        $now = new \DateTimeImmutable('2026-05-15 09:00:00');

        $this->assertSame(
            OnboardingReminderSchedule::STAGE_D_PLUS_5,
            OnboardingReminderSchedule::stageDueOn($now, $signedAt),
        );
    }

    public function testReturnsNullOnDaysWithoutStage(): void
    {
        $signedAt = new \DateTimeImmutable('2026-05-10 14:32:00');

        $this->assertNull(OnboardingReminderSchedule::stageDueOn(
            new \DateTimeImmutable('2026-05-10 23:59:00'),
            $signedAt,
        ));
        $this->assertNull(OnboardingReminderSchedule::stageDueOn(
            new \DateTimeImmutable('2026-05-13 09:00:00'),
            $signedAt,
        ));
        $this->assertNull(OnboardingReminderSchedule::stageDueOn(
            new \DateTimeImmutable('2026-05-20 09:00:00'),
            $signedAt,
        ));
    }

    public function testIntraDayTimingIsIrrelevant(): void
    {
        // signed late on 2026-05-10, cron fires early on 2026-05-12 → still D+2.
        $signedAt = new \DateTimeImmutable('2026-05-10 23:30:00');
        $now = new \DateTimeImmutable('2026-05-12 00:30:00');

        $this->assertSame(
            OnboardingReminderSchedule::STAGE_D_PLUS_2,
            OnboardingReminderSchedule::stageDueOn($now, $signedAt),
        );
    }
}
