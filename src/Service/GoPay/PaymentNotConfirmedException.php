<?php

declare(strict_types=1);

namespace App\Service\GoPay;

/**
 * Thrown when GoPay returns a non-PAID state for a payment.
 *
 * This is NOT a GoPay API error — the API call succeeded,
 * but the payment itself was not confirmed (e.g. card declined, 3DS pending).
 */
final class PaymentNotConfirmedException extends \RuntimeException
{
    public static function withState(string $paymentId, string $state): self
    {
        return new self(sprintf(
            'Payment %s was not confirmed by GoPay (state: %s)',
            $paymentId,
            $state,
        ));
    }
}
