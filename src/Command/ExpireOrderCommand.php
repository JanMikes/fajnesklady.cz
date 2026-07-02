<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;

final readonly class ExpireOrderCommand
{
    public function __construct(
        public Order $order,
    ) {
    }
}
