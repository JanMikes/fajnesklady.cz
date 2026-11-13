<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * What an issued invoice documents. An order collects several invoices over
 * its life (onboarding debt, first payment, every recurring cycle, fines,
 * credit notes) — "the order has an invoice" therefore never means "its first
 * payment is invoiced". 2026-11: an onboarding debt invoice made the first
 * card payment of order 019f5d05 look invoiced, so it never got one.
 */
enum InvoiceKind: string
{
    case FIRST_PAYMENT = 'first_payment';
    case ONBOARDING_DEBT = 'onboarding_debt';
    case RECURRING = 'recurring';
    case FINE = 'fine';
    case CREDIT_NOTE = 'credit_note';
}
