<?php

declare(strict_types=1);

namespace App\Enum;

enum BillingMode: string
{
    case ONE_TIME = 'one_time';
    case AUTO_RECURRING = 'auto_recurring';
    case MANUAL_RECURRING = 'manual_recurring';

    public function isRecurring(): bool
    {
        return self::ONE_TIME !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::ONE_TIME => 'Jednorázová platba',
            self::AUTO_RECURRING => 'Automatická platba kartou',
            self::MANUAL_RECURRING => 'Ručně schvalovaná platba (výzva e-mailem)',
        };
    }
}
