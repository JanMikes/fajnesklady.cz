<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PlaceType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class Place
{
    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    public private(set) ?string $latitude = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 7, nullable: true)]
    public private(set) ?string $longitude = null;

    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $mapImagePath = null;

    #[ORM\Column]
    public private(set) int $daysInAdvance = 0;

    #[ORM\Column]
    public private(set) bool $isActive = true;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\Column(length: 255)]
        private(set) string $name,
        #[ORM\Column(length: 500, nullable: true)]
        private(set) ?string $address,
        #[ORM\Column(length: 100)]
        private(set) string $city,
        #[ORM\Column(length: 20)]
        private(set) string $postalCode,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private(set) ?string $description,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
        #[ORM\Column(length: 20)]
        private(set) PlaceType $type = PlaceType::FAJNE_SKLADY,
    ) {
        $this->updatedAt = $this->createdAt;
    }

    public function hasAddress(): bool
    {
        return $this->address !== null;
    }

    public function updateDetails(
        string $name,
        ?string $address,
        string $city,
        string $postalCode,
        ?string $description,
        PlaceType $type,
        \DateTimeImmutable $now,
    ): void {
        $this->name = $name;
        $this->address = $address;
        $this->city = $city;
        $this->postalCode = $postalCode;
        $this->description = $description;
        $this->type = $type;
        $this->updatedAt = $now;
    }

    public function updateLocation(
        ?string $latitude,
        ?string $longitude,
        \DateTimeImmutable $now,
    ): void {
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        $this->updatedAt = $now;
    }

    public function updateMapImage(?string $mapImagePath, \DateTimeImmutable $now): void
    {
        $this->mapImagePath = $mapImagePath;
        $this->updatedAt = $now;
    }

    public function updateDaysInAdvance(int $daysInAdvance, \DateTimeImmutable $now): void
    {
        $this->daysInAdvance = $daysInAdvance;
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
}
