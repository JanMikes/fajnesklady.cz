<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;

final readonly class InitiateDebtPaymentCommand
{
    public function __construct(
        public Order $order,
        public string $returnUrl,
        public string $notificationUrl,
    ) {
    }
}
