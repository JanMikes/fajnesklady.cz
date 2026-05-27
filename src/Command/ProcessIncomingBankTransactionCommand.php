<?php

declare(strict_types=1);

namespace App\Command;

use App\Value\FioBankTransaction;

final readonly class ProcessIncomingBankTransactionCommand
{
    public function __construct(
        public FioBankTransaction $fioTransaction,
    ) {
    }
}
