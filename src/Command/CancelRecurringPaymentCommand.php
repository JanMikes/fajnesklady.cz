<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;

final readonly class CancelRecurringPaymentCommand
{
    public function __construct(
        public Contract $contract,
    ) {
    }
}
