<?php

declare(strict_types=1);

namespace App\Value;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Storage;
use App\Entity\StorageUnavailability;
use App\Enum\BillingMode;

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

    /**
     * Spec 076 availability guarantee: card-recurring rentals block their unit
     * beyond the span's end (the customer may always prolong).
     */
    public function hasAvailabilityGuarantee(): bool
    {
        return match (true) {
            $this->source instanceof Contract => $this->source->hasAvailabilityGuarantee(),
            $this->source instanceof Order => BillingMode::AUTO_RECURRING === $this->source->billingMode,
            default => false,
        };
    }
}
