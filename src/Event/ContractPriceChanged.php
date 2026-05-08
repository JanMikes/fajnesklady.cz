<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class ContractPriceChanged
{
    public function __construct(
        public Uuid $contractId,
        public ?int $previousAmount,
        public ?int $newAmount,
        public ?User $changedBy,
        public ?string $reason,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
