<?php

declare(strict_types=1);

namespace App\Enum;

enum RequestStatus: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case HANDLED = 'handled';

    public function isPending(): bool
    {
        return self::PENDING === $this;
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::APPROVED, self::REJECTED, self::HANDLED], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Čeká na vyřízení',
            self::APPROVED => 'Schváleno',
            self::REJECTED => 'Zamítnuto',
            self::HANDLED => 'Vyřízeno',
        };
    }
}
