<?php

declare(strict_types=1);

namespace App\Value;

final readonly class FioBankTransaction
{
    public function __construct(
        public string $id,
        public int $amount,
        public string $currency,
        public ?string $variableSymbol,
        public ?string $senderAccountNumber,
        public ?string $senderName,
        public \DateTimeImmutable $date,
        public ?string $comment,
    ) {
    }
}
