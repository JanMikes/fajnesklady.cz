<?php

declare(strict_types=1);

namespace App\Service\Billing;

/**
 * Czech-inflected day counts for the manual-billing reminder e-mails:
 * "1 den", "2/3/4 dny", "5+ dní". The reminder stages fire on
 * place-configurable offsets (and catch-up sends can land off their nominal
 * day), so the subject/body day counts must be computed at send time instead
 * of hardcoding the default -7/-2/0/3/7 cadence.
 */
final readonly class CzechDayCount
{
    public static function days(int $days): string
    {
        $suffix = match (true) {
            1 === $days => 'den',
            $days >= 2 && $days <= 4 => 'dny',
            default => 'dní',
        };

        return sprintf('%d %s', $days, $suffix);
    }
}
