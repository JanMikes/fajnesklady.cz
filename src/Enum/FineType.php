<?php

declare(strict_types=1);

namespace App\Enum;

enum FineType: string
{
    case DIRTY_STORAGE = 'dirty_storage';
    case NON_RETURN = 'non_return';
    case LATE_PAYMENT = 'late_payment';
    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::DIRTY_STORAGE => 'Znečištění skladovací jednotky',
            self::NON_RETURN => 'Nevrácení skladovací jednotky',
            self::LATE_PAYMENT => 'Prodlení s úhradou',
            self::OTHER => 'Jiná pokuta',
        };
    }

    public function defaultAmountInHaler(): ?int
    {
        return match ($this) {
            self::DIRTY_STORAGE => 600_000,
            default => null,
        };
    }
}
