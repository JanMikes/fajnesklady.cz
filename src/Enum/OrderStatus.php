<?php

declare(strict_types=1);

namespace App\Enum;

enum OrderStatus: string
{
    case CREATED = 'created';
    case RESERVED = 'reserved';
    case AWAITING_PAYMENT = 'awaiting_payment';
    case PAID = 'paid';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::EXPIRED], true);
    }
}
