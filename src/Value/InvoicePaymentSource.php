<?php

declare(strict_types=1);

namespace App\Value;

use App\Enum\PaymentMethod;

/**
 * The money an issued invoice documents, as far as the platform can prove:
 * how it arrived and — for a card payment — the GoPay payment that paid it.
 */
final readonly class InvoicePaymentSource
{
    public function __construct(
        public PaymentMethod $method,
        public ?string $goPayPaymentId,
    ) {
    }
}
