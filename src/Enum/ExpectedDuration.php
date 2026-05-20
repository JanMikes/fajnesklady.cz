<?php

declare(strict_types=1);

namespace App\Enum;

enum ExpectedDuration: string
{
    case SHORT = 'short';
    case MEDIUM = 'medium';
    case LONG = 'long';

    public function label(): string
    {
        return match ($this) {
            self::SHORT => '3–6 měsíců',
            self::MEDIUM => '6–12 měsíců',
            self::LONG => 'Více než 1 rok',
        };
    }
}
