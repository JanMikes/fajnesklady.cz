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

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Vytvořeno',
            self::RESERVED => 'Rezervováno',
            self::AWAITING_PAYMENT => 'Čeká na platbu',
            self::PAID => 'Zaplaceno',
            self::COMPLETED => 'Dokončeno',
            self::CANCELLED => 'Zrušeno',
            self::EXPIRED => 'Expirováno',
        };
    }
}
