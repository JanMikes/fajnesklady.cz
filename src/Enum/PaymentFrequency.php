<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentFrequency: string
{
    case MONTHLY = 'monthly';
    case YEARLY = 'yearly';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $frequency) => $frequency->value, self::cases());
    }
}
