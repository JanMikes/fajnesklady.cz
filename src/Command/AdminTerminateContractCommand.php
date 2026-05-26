<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;

final readonly class AdminTerminateContractCommand
{
    public function __construct(
        public Contract $contract,
        public bool $immediate,
        public ?string $reason = null,
    ) {
    }
}
