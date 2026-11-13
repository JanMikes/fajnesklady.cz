<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * How a card-payment refund came about and how its amount was set.
 */
enum RefundMode: string
{
    /** The whole payment returned — admin's choice on termination. */
    case FULL = 'full';

    /** The unused part of the paid period, from an admin-chosen day. */
    case PRORATED = 'prorated';

    /**
     * Issued outside the app (GoPay console) and picked up from GoPay's refund
     * history so the ledger and the order history stay truthful.
     */
    case EXTERNAL = 'external';

    public function label(): string
    {
        return match ($this) {
            self::FULL => 'celá platba',
            self::PRORATED => 'poměrná část',
            self::EXTERNAL => 'vráceno mimo aplikaci (GoPay)',
        };
    }
}
