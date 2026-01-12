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

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\Column(length: 255)]
        private(set) string $name,
        #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
        private(set) string $width,
        #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
        private(set) string $height,
        #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
        private(set) string $length,
        #[ORM\Column]
        private(set) int $pricePerWeek,
        #[ORM\Column]
        private(set) int $pricePerMonth,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) User $owner,
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

    public function getVolume(): float
    {
        return (float) $this->width * (float) $this->height * (float) $this->length;
    }

    public function getDimensions(): string
    {
        return sprintf('%s x %s x %s m', $this->width, $this->height, $this->length);
    }

    public function updateDetails(
        string $name,
        string $width,
        string $height,
        string $length,
        int $pricePerWeek,
        int $pricePerMonth,
        \DateTimeImmutable $now,
    ): void {
        $this->name = $name;
        $this->width = $width;
        $this->height = $height;
        $this->length = $length;
        $this->pricePerWeek = $pricePerWeek;
        $this->pricePerMonth = $pricePerMonth;
        $this->updatedAt = $now;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner->id->equals($user->id);
    }
}
