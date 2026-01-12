<?php

declare(strict_types=1);

namespace App\Enum;

enum RentalType: string
{
    case LIMITED = 'limited';
    case UNLIMITED = 'unlimited';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
