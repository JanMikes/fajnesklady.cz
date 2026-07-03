<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\StorageCodeUsageType;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'place_storage_code_usage')]
#[ORM\UniqueConstraint(name: 'uniq_place_storage_code_usage_place_code', columns: ['place_id', 'code'])]
class PlaceStorageCodeUsage
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Place::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) Place $place,
        #[ORM\Column(length: 20)]
        private(set) string $code,
        #[ORM\Column(length: 20, enumType: StorageCodeUsageType::class, options: ['default' => 'used'])]
        private(set) StorageCodeUsageType $type,
        #[ORM\Column(length: 255, nullable: true)]
        private(set) ?string $note,
        #[ORM\Column]
        private(set) \DateTimeImmutable $usedAt,
    ) {
    }

    public function exclude(?string $note): void
    {
        $this->type = StorageCodeUsageType::EXCLUDED;
        $this->note = $note;
    }
}
