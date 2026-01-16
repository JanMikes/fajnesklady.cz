<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StorageStatus;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
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

    #[ORM\Column(nullable: true)]
    public private(set) ?int $pricePerWeek = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $pricePerMonth = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public private(set) ?User $owner = null;

    /** @var Collection<int, StoragePhoto> */
    #[ORM\OneToMany(targetEntity: StoragePhoto::class, mappedBy: 'storage', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $photos;

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
        #[ORM\ManyToOne(targetEntity: Place::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) Place $place,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
        ?User $owner = null,
    ) {
        $this->status = StorageStatus::AVAILABLE;
        $this->updatedAt = $createdAt;
        $this->photos = new ArrayCollection();
        $this->owner = $owner;
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
        return $this->place;
    }

    public function isOwnedBy(User $user): bool
    {
        return null !== $this->owner && $this->owner->id->equals($user->id);
    }

    public function hasOwner(): bool
    {
        return null !== $this->owner;
    }

    public function assignOwner(User $owner, \DateTimeImmutable $now): void
    {
        $this->owner = $owner;
        $this->updatedAt = $now;
    }

    public function removeOwner(\DateTimeImmutable $now): void
    {
        $this->owner = null;
        $this->updatedAt = $now;
    }

    public function updatePrices(?int $pricePerWeek, ?int $pricePerMonth, \DateTimeImmutable $now): void
    {
        $this->pricePerWeek = $pricePerWeek;
        $this->pricePerMonth = $pricePerMonth;
        $this->updatedAt = $now;
    }

    public function getEffectivePricePerWeek(): int
    {
        return $this->pricePerWeek ?? $this->storageType->defaultPricePerWeek;
    }

    public function getEffectivePricePerMonth(): int
    {
        return $this->pricePerMonth ?? $this->storageType->defaultPricePerMonth;
    }

    public function getEffectivePricePerWeekInCzk(): float
    {
        return $this->getEffectivePricePerWeek() / 100;
    }

    public function getEffectivePricePerMonthInCzk(): float
    {
        return $this->getEffectivePricePerMonth() / 100;
    }

    public function hasCustomPrices(): bool
    {
        return null !== $this->pricePerWeek || null !== $this->pricePerMonth;
    }

    /**
     * @return Collection<int, StoragePhoto>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(StoragePhoto $photo): void
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
        }
    }

    public function removePhoto(StoragePhoto $photo): void
    {
        $this->photos->removeElement($photo);
    }

    public function hasPhotos(): bool
    {
        return !$this->photos->isEmpty();
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
