<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What ChargeRecurringPaymentHandler actually did for a contract. The crons
 * print and count from this — a run must never report "charged" unless GoPay
 * confirmed the money (a declined card throws instead and never gets here).
 */
enum RecurringChargeOutcome: string
{
    /** GoPay confirmed PAID and this run recorded the payment. */
    case CHARGED = 'charged';

    /** GoPay confirmed PAID but the webhook had already recorded it. */
    case ALREADY_RECORDED = 'already_recorded';

    /** GoPay is still processing the card; the webhook / next run will settle it. */
    case STILL_PROCESSING = 'still_processing';

    /** Contract was billed minutes ago (cron vs. manual charge race). */
    case SKIPPED_RECENTLY_BILLED = 'skipped_recently_billed';

    /** Nothing to charge for this cycle. */
    case SKIPPED_ZERO_AMOUNT = 'skipped_zero_amount';

    public function isPaid(): bool
    {
        return self::CHARGED === $this || self::ALREADY_RECORDED === $this;
    }

    public function describe(): string
    {
        return match ($this) {
            self::CHARGED => 'charged — payment confirmed by GoPay',
            self::ALREADY_RECORDED => 'paid — already recorded by the GoPay webhook',
            self::STILL_PROCESSING => 'NOT charged yet — GoPay still processing, will be settled by webhook / next run',
            self::SKIPPED_RECENTLY_BILLED => 'skipped — billed within the last 5 minutes',
            self::SKIPPED_ZERO_AMOUNT => 'skipped — nothing to charge',
        };
    }
}
