<?php

declare(strict_types=1);

namespace App\Value;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\StorageUnavailability;

final readonly class RentalSpan
{
    public function __construct(
        public Storage $storage,
        public RentalSpanKind $kind,
        public \DateTimeImmutable $startDate,
        public ?\DateTimeImmutable $endDate,
        public ?string $tenantName,
        public Contract|Order|StorageUnavailability $source,
    ) {
    }
}
