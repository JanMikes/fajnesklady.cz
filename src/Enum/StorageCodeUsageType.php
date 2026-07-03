<?php

declare(strict_types=1);

namespace App\Enum;

enum StorageCodeUsageType: string
{
    case USED = 'used';
    case EXCLUDED = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::USED => 'Použitý',
            self::EXCLUDED => 'Vyloučený',
        };
    }
}
