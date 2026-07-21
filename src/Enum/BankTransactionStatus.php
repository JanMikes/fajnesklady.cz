<?php

declare(strict_types=1);

namespace App\Enum;

enum BankTransactionStatus: string
{
    case UNMATCHED = 'unmatched';
    case MATCHED = 'matched';

    /**
     * Paired to an order, but this transfer alone did not fully settle the
     * obligation it was allocated against — an admin signal, not an error.
     */
    case AMOUNT_MISMATCH = 'amount_mismatch';

    case IGNORED = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::UNMATCHED => 'Nespárováno',
            self::MATCHED => 'Spárováno',
            self::AMOUNT_MISMATCH => 'Částečná úhrada',
            self::IGNORED => 'Nesouvisející',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::MATCHED => 'badge-success',
            self::UNMATCHED => 'badge-warning',
            self::AMOUNT_MISMATCH => 'badge-error',
            self::IGNORED => 'badge-ghost',
        };
    }
}
