<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * HOW money reaches us — the one vocabulary for "způsob platby" across the
 * admin, the customer portal and the Fakturoid invoices. Cadence (one-shot,
 * automatic card debit, e-mailed payment request) is a separate concept:
 * {@see BillingMode}.
 */
enum PaymentMethod: string
{
    case GOPAY = 'gopay';
    case EXTERNAL = 'external';
    case BANK_TRANSFER = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::GOPAY => 'Platební karta (GoPay)',
            self::BANK_TRANSFER => 'Bankovní převod',
            // Money an admin recorded by hand — it never passed through GoPay
            // or our FIO account (onboarding migrations, cash, …).
            self::EXTERNAL => 'Mimo systém',
        };
    }

    /**
     * Fakturoid invoice `payment_method` — printed on the PDF as "Způsob
     * platby", which is what the accountant reads to tell card from transfer.
     * Off-system money is never invoiced (no evidence of the payment), so it
     * has no Fakturoid counterpart.
     */
    public function fakturoidPaymentMethod(): string
    {
        return match ($this) {
            self::GOPAY => 'card',
            self::BANK_TRANSFER => 'bank',
            self::EXTERNAL => throw new \LogicException('Off-system payments are never invoiced.'),
        };
    }
}
