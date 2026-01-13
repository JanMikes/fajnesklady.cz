<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class StorageTypePhoto
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: StorageType::class, inversedBy: 'photos')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) StorageType $storageType,
        #[ORM\Column(length: 500)]
        private(set) string $path,
        #[ORM\Column]
        public private(set) int $position,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
    }

    public function updatePosition(int $position): void
    {
        $this->position = $position;
    }
}
