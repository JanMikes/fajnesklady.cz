<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

/**
 * GoPay reported a payment as PAID but there is nowhere to apply the money:
 * the order died in the meantime (expiry/cancellation raced the customer's
 * gateway session), the payment ID matches nothing we track (an orphaned
 * older session the customer paid from a second tab), or a card-setup charge
 * landed on a terminated contract.
 *
 * Nothing is mutated automatically in these situations — the admin decides
 * between refund and manual completion. This event exists so the situation is
 * IMPOSSIBLE to miss: without it the money would only be visible in GoPay's
 * console.
 */
final readonly class UnaccountedPaidPayment
{
    public const string REASON_ORDER_CANCELLED = 'order_cancelled';
    public const string REASON_ORDER_EXPIRED = 'order_expired';
    public const string REASON_UNKNOWN_PAYMENT = 'unknown_payment';
    public const string REASON_UNKNOWN_RECURRING_PARENT = 'unknown_recurring_parent';
    public const string REASON_CARD_SETUP_CONTRACT_TERMINATED = 'card_setup_contract_terminated';

    public function __construct(
        public string $goPayPaymentId,
        public string $reason,
        public ?int $amount,
        public ?Uuid $orderId,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
