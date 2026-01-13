<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Uid\Uuid;

final readonly class CreateStorageUnavailabilityCommand
{
    public function __construct(
        public Uuid $storageId,
        public \DateTimeImmutable $startDate,
        public ?\DateTimeImmutable $endDate,
        public string $reason,
        public Uuid $createdById,
    ) {
    }
}
