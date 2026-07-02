<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class StorageType
{
    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $description = null;

    #[ORM\Column]
    public private(set) bool $isActive = true;

    #[ORM\Column]
    public private(set) bool $uniformStorages = true;

    /**
     * Admin-curated display order within the place. Drives the ordering of
     * storage types everywhere they are listed (place detail, price list,
     * order flow, portal). Lower comes first; ties fall back to name.
     */
    #[ORM\Column(options: ['default' => 0])]
    public private(set) int $position = 0;

    /**
     * When true the type is never offered to customers: it is hidden from the
     * public homepage, place detail, price list and tenant browse, and the
     * public order routes reject it. Only an admin can place a customer into it
     * (via onboarding). Landlords still manage it normally in the portal.
     */
    #[ORM\Column(options: ['default' => false])]
    public private(set) bool $adminOnly = false;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $outerWidth = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $outerHeight = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $outerLength = null;

    #[ORM\Column]
    public private(set) int $defaultPricePerYear;

    /** @var Collection<int, StorageTypePhoto> */
    #[ORM\OneToMany(targetEntity: StorageTypePhoto::class, mappedBy: 'storageType', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $photos;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Place::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) Place $place,
        #[ORM\Column(length: 255)]
        private(set) string $name,
        #[ORM\Column]
        private(set) int $innerWidth,
        #[ORM\Column]
        private(set) int $innerHeight,
        #[ORM\Column]
        private(set) int $innerLength,
        #[ORM\Column]
        private(set) int $defaultPricePerWeek,
        #[ORM\Column]
        private(set) int $defaultPricePerMonth,
        #[ORM\Column]
        private(set) int $defaultPricePerMonthLongTerm,
        int $defaultPricePerYear,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
        bool $uniformStorages = true,
        ?int $outerWidth = null,
        ?int $outerHeight = null,
        ?int $outerLength = null,
        bool $adminOnly = false,
    ) {
        $this->updatedAt = $createdAt;
        $this->photos = new ArrayCollection();
        $this->uniformStorages = $uniformStorages;
        $this->adminOnly = $adminOnly;
        $this->outerWidth = $outerWidth;
        $this->outerHeight = $outerHeight;
        $this->outerLength = $outerLength;
        $this->defaultPricePerYear = $defaultPricePerYear;
    }

    public function getDefaultPricePerWeekInCzk(): float
    {
        return $this->defaultPricePerWeek / 100;
    }

    public function getDefaultPricePerMonthInCzk(): float
    {
        return $this->defaultPricePerMonth / 100;
    }

    public function getDefaultPricePerMonthLongTermInCzk(): float
    {
        return $this->defaultPricePerMonthLongTerm / 100;
    }

    public function getDefaultPricePerYearInCzk(): float
    {
        return $this->defaultPricePerYear / 100;
    }

    public function getFloorAreaInSquareMeters(): float
    {
        return ($this->innerLength / 100) * ($this->innerWidth / 100);
    }

    public function getVolumeInCubicMeters(): float
    {
        return ($this->innerWidth / 100) * ($this->innerHeight / 100) * ($this->innerLength / 100);
    }

    public function getInnerDimensions(): string
    {
        return sprintf('%d x %d x %d cm', $this->innerWidth, $this->innerHeight, $this->innerLength);
    }

    public function getInnerDimensionsInMeters(): string
    {
        return sprintf('%.2f x %.2f x %.2f m', $this->innerWidth / 100, $this->innerHeight / 100, $this->innerLength / 100);
    }

    public function getOuterDimensions(): ?string
    {
        if (!$this->hasOuterDimensions()) {
            return null;
        }

        return sprintf('%d x %d x %d cm', $this->outerWidth, $this->outerHeight, $this->outerLength);
    }

    public function getOuterDimensionsInMeters(): ?string
    {
        if (!$this->hasOuterDimensions()) {
            return null;
        }

        return sprintf('%.2f x %.2f x %.2f m', $this->outerWidth / 100, $this->outerHeight / 100, $this->outerLength / 100);
    }

    public function hasOuterDimensions(): bool
    {
        return null !== $this->outerWidth && null !== $this->outerHeight && null !== $this->outerLength;
    }

    /**
     * @deprecated Use getInnerDimensions() instead
     */
    public function getDimensions(): string
    {
        return $this->getInnerDimensions();
    }

    /**
     * @deprecated Use getInnerDimensionsInMeters() instead
     */
    public function getDimensionsInMeters(): string
    {
        return $this->getInnerDimensionsInMeters();
    }

    public function updateDetails(
        string $name,
        int $innerWidth,
        int $innerHeight,
        int $innerLength,
        ?int $outerWidth,
        ?int $outerHeight,
        ?int $outerLength,
        int $defaultPricePerWeek,
        int $defaultPricePerMonth,
        int $defaultPricePerMonthLongTerm,
        int $defaultPricePerYear,
        ?string $description,
        bool $uniformStorages,
        bool $adminOnly,
        \DateTimeImmutable $now,
    ): void {
        $this->name = $name;
        $this->innerWidth = $innerWidth;
        $this->innerHeight = $innerHeight;
        $this->innerLength = $innerLength;
        $this->outerWidth = $outerWidth;
        $this->outerHeight = $outerHeight;
        $this->outerLength = $outerLength;
        $this->defaultPricePerWeek = $defaultPricePerWeek;
        $this->defaultPricePerMonth = $defaultPricePerMonth;
        $this->defaultPricePerMonthLongTerm = $defaultPricePerMonthLongTerm;
        $this->defaultPricePerYear = $defaultPricePerYear;
        $this->description = $description;
        $this->uniformStorages = $uniformStorages;
        $this->adminOnly = $adminOnly;
        $this->updatedAt = $now;
    }

    public function setOuterDimensions(?int $outerWidth, ?int $outerHeight, ?int $outerLength, \DateTimeImmutable $now): void
    {
        $this->outerWidth = $outerWidth;
        $this->outerHeight = $outerHeight;
        $this->outerLength = $outerLength;
        $this->updatedAt = $now;
    }

    public function updatePosition(int $position, \DateTimeImmutable $now): void
    {
        $this->position = $position;
        $this->updatedAt = $now;
    }

    public function activate(\DateTimeImmutable $now): void
    {
        $this->isActive = true;
        $this->updatedAt = $now;
    }

    public function deactivate(\DateTimeImmutable $now): void
    {
        $this->isActive = false;
        $this->updatedAt = $now;
    }

    /**
     * @return Collection<int, StorageTypePhoto>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(StorageTypePhoto $photo): void
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
        }
    }

    public function removePhoto(StorageTypePhoto $photo): void
    {
        $this->photos->removeElement($photo);
    }

    public function hasPhotos(): bool
    {
        return !$this->photos->isEmpty();
    }

    public function softDelete(\DateTimeImmutable $now): void
    {
        $this->deletedAt = $now;
        $this->updatedAt = $now;
    }

    public function isDeleted(): bool
    {
        return null !== $this->deletedAt;
    }
}
