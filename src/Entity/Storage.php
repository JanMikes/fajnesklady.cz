<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StorageStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class Storage
{
    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;

    #[ORM\Column(length: 30, enumType: StorageStatus::class)]
    public private(set) StorageStatus $status;

    /**
     * @param array{x: int, y: int, width: int, height: int, rotation: int} $coordinates
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\Column(length: 20)]
        private(set) string $number,
        #[ORM\Column(type: Types::JSON)]
        private(set) array $coordinates,
        #[ORM\ManyToOne(targetEntity: StorageType::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) StorageType $storageType,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->status = StorageStatus::AVAILABLE;
        $this->updatedAt = $createdAt;
    }

    public function reserve(\DateTimeImmutable $now): void
    {
        $this->status = StorageStatus::RESERVED;
        $this->updatedAt = $now;
    }

    public function occupy(\DateTimeImmutable $now): void
    {
        $this->status = StorageStatus::OCCUPIED;
        $this->updatedAt = $now;
    }

    public function release(\DateTimeImmutable $now): void
    {
        $this->status = StorageStatus::AVAILABLE;
        $this->updatedAt = $now;
    }

    public function markUnavailable(\DateTimeImmutable $now): void
    {
        $this->status = StorageStatus::MANUALLY_UNAVAILABLE;
        $this->updatedAt = $now;
    }

    /**
     * @param array{x: int, y: int, width: int, height: int, rotation: int} $coordinates
     */
    public function updateDetails(
        string $number,
        array $coordinates,
        \DateTimeImmutable $now,
    ): void {
        $this->number = $number;
        $this->coordinates = $coordinates;
        $this->updatedAt = $now;
    }

    public function getPlace(): Place
    {
        return $this->storageType->place;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->storageType->isOwnedBy($user);
    }

    public function isAvailable(): bool
    {
        return StorageStatus::AVAILABLE === $this->status;
    }

    public function isReserved(): bool
    {
        return StorageStatus::RESERVED === $this->status;
    }

    public function isOccupied(): bool
    {
        return StorageStatus::OCCUPIED === $this->status;
    }
}
