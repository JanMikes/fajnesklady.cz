<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

/**
 * A recurring cycle was settled. Carries WHERE the money came from explicitly:
 * a GoPay card charge id, or the bank transaction of a transfer. The two used
 * to share one string field, which put bank-transaction UUIDs into
 * payment.go_pay_payment_id — and every such payment then rendered as
 * "Kartou (GoPay)" although the customer paid by wire.
 */
final readonly class RecurringPaymentCharged
{
    private function __construct(
        public Uuid $contractId,
        public ?string $goPayPaymentId,
        public ?Uuid $bankTransactionId,
        public int $amount,
        public \DateTimeImmutable $occurredOn,
    ) {
    }

    public static function viaGoPay(
        Uuid $contractId,
        string $goPayPaymentId,
        int $amount,
        \DateTimeImmutable $occurredOn,
    ): self {
        return new self($contractId, $goPayPaymentId, null, $amount, $occurredOn);
    }

    public static function viaBankTransfer(
        Uuid $contractId,
        Uuid $bankTransactionId,
        int $amount,
        \DateTimeImmutable $occurredOn,
    ): self {
        return new self($contractId, null, $bankTransactionId, $amount, $occurredOn);
    }
}
