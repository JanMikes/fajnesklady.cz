<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class OnboardingPaymentReminderRequested
{
    public function __construct(
        public Uuid $orderId,
        public string $stage,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
