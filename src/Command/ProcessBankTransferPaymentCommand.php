<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BankTransaction;
use App\Entity\Order;

final readonly class ProcessBankTransferPaymentCommand
{
    public function __construct(
        public BankTransaction $transaction,
        public Order $order,
    ) {
    }
}
