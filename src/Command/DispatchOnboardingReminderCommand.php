<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class DispatchOnboardingReminderCommand
{
    public function __construct(
        public Uuid $orderId,
        public string $stage,
    ) {
    }
}
