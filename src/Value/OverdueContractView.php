<?php

declare(strict_types=1);

namespace App\Value;

use App\Entity\Contract;

final readonly class OverdueContractView
{
    public function __construct(
        public Contract $contract,
        public int $daysOverdue,
        public int $overdueAmount,
        public OverdueSeverity $severity,
        public string $reasonLabel,
        public \DateTimeImmutable $anchorDate,
    ) {
    }

    public function getOverdueAmountInCzk(): float
    {
        return $this->overdueAmount / 100;
    }
}
