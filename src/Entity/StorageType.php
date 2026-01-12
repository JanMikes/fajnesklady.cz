<?php

declare(strict_types=1);

namespace App\Entity;

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

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\Column(length: 255)]
        private(set) string $name,
        #[ORM\Column]
        private(set) int $width,
        #[ORM\Column]
        private(set) int $height,
        #[ORM\Column]
        private(set) int $length,
        #[ORM\Column]
        private(set) int $pricePerWeek,
        #[ORM\Column]
        private(set) int $pricePerMonth,
        #[ORM\ManyToOne(targetEntity: Place::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) Place $place,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $createdAt;
    }

    public function getPricePerWeekInCzk(): float
    {
        return $this->pricePerWeek / 100;
    }

    public function getPricePerMonthInCzk(): float
    {
        return $this->pricePerMonth / 100;
    }

    public function getVolumeInCubicMeters(): float
    {
        return ($this->width / 100) * ($this->height / 100) * ($this->length / 100);
    }

    public function getDimensions(): string
    {
        return sprintf('%d x %d x %d cm', $this->width, $this->height, $this->length);
    }

    public function getDimensionsInMeters(): string
    {
        return sprintf('%.2f x %.2f x %.2f m', $this->width / 100, $this->height / 100, $this->length / 100);
    }

    public function updateDetails(
        string $name,
        int $width,
        int $height,
        int $length,
        int $pricePerWeek,
        int $pricePerMonth,
        ?string $description,
        \DateTimeImmutable $now,
    ): void {
        $this->name = $name;
        $this->width = $width;
        $this->height = $height;
        $this->length = $length;
        $this->pricePerWeek = $pricePerWeek;
        $this->pricePerMonth = $pricePerMonth;
        $this->description = $description;
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

    public function belongsToPlace(Place $place): bool
    {
        return $this->place->id->equals($place->id);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->place->isOwnedBy($user);
    }
}
