<?php

declare(strict_types=1);

namespace App\Event;

use App\Enum\FineType;
use Symfony\Component\Uid\Uuid;

final readonly class FineIssued
{
    public function __construct(
        public Uuid $fineId,
        public Uuid $contractId,
        public Uuid $userId,
        public FineType $type,
        public int $amountInHaler,
        public \DateTimeImmutable $occurredOn,
    ) {
    }
}
