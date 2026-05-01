<?php

declare(strict_types=1);

namespace App\Enum;

enum EmailLogStatus: string
{
    case SENT = 'sent';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::SENT => 'Odesláno',
            self::FAILED => 'Selhalo',
        };
    }
}
