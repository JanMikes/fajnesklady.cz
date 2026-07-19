<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;

final readonly class SettleContractDebtCommand
{
    public function __construct(
        public Contract $contract,
        public int $amountInHaler,
        public bool $issueInvoice,
    ) {
    }
}
