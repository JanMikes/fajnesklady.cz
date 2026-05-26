<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class InitiateFinePaymentCommand
{
    public function __construct(
        public Uuid $fineId,
        public string $returnUrl,
        public string $notificationUrl,
    ) {
    }
}
