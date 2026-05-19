<?php

declare(strict_types=1);

namespace App\Service\Onboarding;

/**
 * Calendar-day schedule for the onboarding-payment reminder cron. The customer
 * signed but hasn't paid; we nudge twice (D+2 and D+5) before letting the
 * expire-orders cron sweep it up. Calendar-day comparison (truncated to
 * midnight) so the cron's time-of-day doesn't matter — only the date diff.
 */
final readonly class OnboardingReminderSchedule
{
    public const string STAGE_D_PLUS_2 = 'd_plus_2';
    public const string STAGE_D_PLUS_5 = 'd_plus_5';

    /**
     * @return array<string, int>
     */
    public static function stages(): array
    {
        return [
            self::STAGE_D_PLUS_2 => 2,
            self::STAGE_D_PLUS_5 => 5,
        ];
    }

    public static function stageDueOn(\DateTimeImmutable $now, \DateTimeImmutable $signedAt): ?string
    {
        $today = $now->setTime(0, 0, 0);
        $anchor = $signedAt->setTime(0, 0, 0);
        $diffDays = (int) $anchor->diff($today)->format('%r%a');

        foreach (self::stages() as $stage => $offset) {
            if ($offset === $diffDays) {
                return $stage;
            }
        }

        return null;
    }
}
