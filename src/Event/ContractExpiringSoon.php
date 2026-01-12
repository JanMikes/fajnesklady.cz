<?php

declare(strict_types=1);

namespace App\Event;

use Symfony\Component\Uid\Uuid;

/**
 * Event dispatched when a contract is expiring soon.
 * Used to send reminder emails to users.
 */
final readonly class ContractExpiringSoon
{
    public function __construct(
        public Uuid $contractId,
        public int $daysRemaining,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
