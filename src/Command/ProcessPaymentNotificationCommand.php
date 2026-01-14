<?php

declare(strict_types=1);

namespace App\Command;

final readonly class ProcessPaymentNotificationCommand
{
    public function __construct(
        public int $goPayPaymentId,
    ) {
    }
}
