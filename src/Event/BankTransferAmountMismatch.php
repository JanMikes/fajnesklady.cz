<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class BankTransferAmountMismatch
{
    public function __construct(
        public Uuid $bankTransactionId,
        public ?Uuid $orderId,
        public ?Uuid $contractId,
        public int $expectedAmount,
        public int $receivedAmount,
        public ?string $variableSymbol,
        public \DateTimeImmutable $occurredOn,
    ) {
        if (null === $orderId && null === $contractId) {
            throw new \InvalidArgumentException('BankTransferAmountMismatch requires either orderId or contractId.');
        }
    }

    public static function forOrder(
        Uuid $bankTransactionId,
        Uuid $orderId,
        int $expectedAmount,
        int $receivedAmount,
        ?string $variableSymbol,
        \DateTimeImmutable $occurredOn,
    ): self {
        return new self(
            bankTransactionId: $bankTransactionId,
            orderId: $orderId,
            contractId: null,
            expectedAmount: $expectedAmount,
            receivedAmount: $receivedAmount,
            variableSymbol: $variableSymbol,
            occurredOn: $occurredOn,
        );
    }

    public static function forContract(
        Uuid $bankTransactionId,
        Uuid $contractId,
        Uuid $orderId,
        int $expectedAmount,
        int $receivedAmount,
        ?string $variableSymbol,
        \DateTimeImmutable $occurredOn,
    ): self {
        return new self(
            bankTransactionId: $bankTransactionId,
            orderId: $orderId,
            contractId: $contractId,
            expectedAmount: $expectedAmount,
            receivedAmount: $receivedAmount,
            variableSymbol: $variableSymbol,
            occurredOn: $occurredOn,
        );
    }
}
