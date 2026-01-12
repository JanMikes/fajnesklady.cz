<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class StorageUnavailability
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Storage::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) Storage $storage,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $startDate,
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private(set) ?\DateTimeImmutable $endDate,
        #[ORM\Column(length: 500)]
        private(set) string $reason,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) User $createdBy,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
    }

    public function isIndefinite(): bool
    {
        return null === $this->endDate;
    }

    public function isActiveOn(\DateTimeImmutable $date): bool
    {
        if ($date < $this->startDate) {
            return false;
        }

        if (null === $this->endDate) {
            return true;
        }

        return $date <= $this->endDate;
    }

    public function overlapsWithPeriod(\DateTimeImmutable $start, ?\DateTimeImmutable $end): bool
    {
        // If both end dates are null, they overlap (both indefinite)
        if (null === $this->endDate && null === $end) {
            return true;
        }

        // If this unavailability is indefinite
        if (null === $this->endDate) {
            return $end >= $this->startDate;
        }

        // If the requested period is indefinite
        if (null === $end) {
            return $this->endDate >= $start;
        }

        // Standard overlap check
        return $this->startDate <= $end && $start <= $this->endDate;
    }
}
