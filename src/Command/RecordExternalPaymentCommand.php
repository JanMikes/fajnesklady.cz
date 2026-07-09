<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;

final readonly class RecordExternalPaymentCommand
{
    public function __construct(
        public Order $order,
        public bool $wholeCycle,
        public ?\DateTimeImmutable $paidThroughDate,
        public int $amount,
        public bool $issueInvoice,
    ) {
    }
}
