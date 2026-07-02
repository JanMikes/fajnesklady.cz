<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\User;
use Symfony\Component\Uid\Uuid;

final readonly class ContractProlonged
{
    public function __construct(
        public Uuid $contractId,
        public \DateTimeImmutable $previousEndDate,
        public \DateTimeImmutable $newEndDate,
        public ?User $prolongedBy,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
