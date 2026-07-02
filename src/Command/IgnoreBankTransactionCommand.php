<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class IgnoreBankTransactionCommand
{
    public function __construct(
        public Uuid $transactionId,
        public Uuid $adminId,
        public ?string $reason,
    ) {
    }
}
