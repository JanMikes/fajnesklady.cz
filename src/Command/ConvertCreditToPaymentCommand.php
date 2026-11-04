<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BankTransaction;
use App\Entity\Contract;

/**
 * Turn a contract's credit balance into a recorded, invoiced payment — for
 * credit that came from a bank transfer which over-paid a cycle and can no
 * longer be used up (e.g. the contract ended). $bankTransaction is the
 * transfer the surplus came from: it is the payment evidence the invoice
 * (daňový doklad) rests on.
 */
final readonly class ConvertCreditToPaymentCommand
{
    public function __construct(
        public Contract $contract,
        public BankTransaction $bankTransaction,
    ) {
    }
}
