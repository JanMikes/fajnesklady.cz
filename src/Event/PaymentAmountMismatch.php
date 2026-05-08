<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

/**
 * Fired when GoPay reports a payment with an amount that differs from what
 * we expected (the locked-in monthly for recurring, `Order.firstPaymentPrice`
 * for the order branch). GoPay is the source of truth for what was actually
 * charged, so we still record what GoPay says — but admin needs visibility
 * to investigate (proration on the last cycle of a fixed-term contract is
 * legitimate; everything else likely needs a refund or contract update).
 *
 * Exactly one of `contractId` / `orderId` is non-null:
 *  - contractId set → recurring branch (`ProcessPaymentNotificationHandler::reconcileRecurringPayment`)
 *  - orderId set    → order branch (initial GoPay charge)
 */
final readonly class PaymentAmountMismatch
{
    public function __construct(
        public ?Uuid $contractId,
        public ?Uuid $orderId,
        public string $goPayPaymentId,
        public int $expectedAmount,
        public int $receivedAmount,
        public \DateTimeImmutable $occurredOn,
    ) {
        if (null === $contractId && null === $orderId) {
            throw new \InvalidArgumentException('PaymentAmountMismatch requires either contractId or orderId.');
        }

        if (null !== $contractId && null !== $orderId) {
            throw new \InvalidArgumentException('PaymentAmountMismatch requires exactly one of contractId or orderId, not both.');
        }
    }

    public static function forContract(
        Uuid $contractId,
        string $goPayPaymentId,
        int $expectedAmount,
        int $receivedAmount,
        \DateTimeImmutable $occurredOn,
    ): self {
        return new self(
            contractId: $contractId,
            orderId: null,
            goPayPaymentId: $goPayPaymentId,
            expectedAmount: $expectedAmount,
            receivedAmount: $receivedAmount,
            occurredOn: $occurredOn,
        );
    }

    public static function forOrder(
        Uuid $orderId,
        string $goPayPaymentId,
        int $expectedAmount,
        int $receivedAmount,
        \DateTimeImmutable $occurredOn,
    ): self {
        return new self(
            contractId: null,
            orderId: $orderId,
            goPayPaymentId: $goPayPaymentId,
            expectedAmount: $expectedAmount,
            receivedAmount: $receivedAmount,
            occurredOn: $occurredOn,
        );
    }
}
