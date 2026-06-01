<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class OnboardingDebtPaid
{
    public function __construct(
        public Uuid $orderId,
        public Uuid $userId,
        public int $amountInHaler,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
