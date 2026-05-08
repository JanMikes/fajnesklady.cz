<?php

declare(strict_types=1);

namespace App\Service\GoPay;

/**
 * Thrown when GoPay returns a non-PAID state for a payment.
 *
 * This is NOT a GoPay API error — the API call succeeded,
 * but the payment itself was not confirmed (e.g. card declined, 3DS pending).
 *
 * The {@see $state} field carries GoPay's reported state so callers can
 * distinguish a polling timeout while the card is still being processed
 * (`CREATED`, `AUTHORIZED`, `PAYMENT_METHOD_CHOSEN` — webhook will reconcile)
 * from a terminal failure (`CANCELED`, `TIMEOUTED`, `REFUNDED`).
 *
 * Constructor parameters all have defaults so the class is autowire-safe under
 * the `App\Service\` service definition; instantiate via {@see self::withState()}.
 */
final class PaymentNotConfirmedException extends \RuntimeException
{
    /**
     * GoPay states that mean the charge is still being processed asynchronously.
     * The notification webhook is the authoritative source of truth for these.
     */
    private const array PENDING_STATES = ['CREATED', 'PAYMENT_METHOD_CHOSEN', 'AUTHORIZED'];

    public function __construct(
        string $message = '',
        public readonly string $paymentId = '',
        public readonly string $state = '',
    ) {
        parent::__construct($message);
    }

    public static function withState(string $paymentId, string $state): self
    {
        return new self(
            sprintf('Payment %s was not confirmed by GoPay (state: %s)', $paymentId, $state),
            $paymentId,
            $state,
        );
    }

    /**
     * True when GoPay is still processing the charge asynchronously and the
     * notification webhook is expected to reconcile the contract. Callers
     * SHOULD NOT count this as a billing failure.
     */
    public function isPending(): bool
    {
        return in_array($this->state, self::PENDING_STATES, true);
    }
}
