<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;

final readonly class AcceptOrderTermsCommand
{
    public function __construct(
        public Order $order,
    ) {
    }
}
