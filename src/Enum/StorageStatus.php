<?php

declare(strict_types=1);

namespace App\Enum;

enum StorageStatus: string
{
    case AVAILABLE = 'available';
    case RESERVED = 'reserved';
    case OCCUPIED = 'occupied';
    case MANUALLY_UNAVAILABLE = 'manually_unavailable';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
