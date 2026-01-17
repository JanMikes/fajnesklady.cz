<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class GenerateSelfBillingInvoiceCommand
{
    public function __construct(
        public Uuid $landlordId,
        public int $year,
        public int $month,
    ) {
    }
}
