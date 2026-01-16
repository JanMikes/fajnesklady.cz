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

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $description = null;

    #[ORM\Column]
    public private(set) bool $isActive = true;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $outerWidth = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $outerHeight = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $outerLength = null;

    /** @var Collection<int, StorageTypePhoto> */
    #[ORM\OneToMany(targetEntity: StorageTypePhoto::class, mappedBy: 'storageType', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $photos;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
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
        private(set) \DateTimeImmutable $createdAt,
        ?int $outerWidth = null,
        ?int $outerHeight = null,
        ?int $outerLength = null,
    ) {
        $this->updatedAt = $createdAt;
        $this->photos = new ArrayCollection();
        $this->outerWidth = $outerWidth;
        $this->outerHeight = $outerHeight;
        $this->outerLength = $outerLength;
    }

    public function getDefaultPricePerWeekInCzk(): float
    {
        return $this->defaultPricePerWeek / 100;
    }

    public function getDefaultPricePerMonthInCzk(): float
    {
        return $this->defaultPricePerMonth / 100;
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
        ?string $description,
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
        $this->description = $description;
        $this->updatedAt = $now;
    }

    public function setOuterDimensions(?int $outerWidth, ?int $outerHeight, ?int $outerLength, \DateTimeImmutable $now): void
    {
        $this->outerWidth = $outerWidth;
        $this->outerHeight = $outerHeight;
        $this->outerLength = $outerLength;
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
}
