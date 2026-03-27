<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

final readonly class AdminOnboardingInitiated
{
    public function __construct(
        public Uuid $orderId,
        public Uuid $userId,
        public string $customerEmail,
        public ?string $signingToken,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
