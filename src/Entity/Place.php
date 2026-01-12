<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class Place
{
    #[ORM\Column]
    public private(set) \DateTimeImmutable $updatedAt;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\Column(length: 255)]
        private(set) string $name,
        #[ORM\Column(length: 500)]
        private(set) string $address,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private(set) ?string $description,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) User $owner,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->updatedAt = $this->createdAt;
    }

    public function updateDetails(
        string $name,
        string $address,
        ?string $description,
        \DateTimeImmutable $now,
    ): void {
        $this->name = $name;
        $this->address = $address;
        $this->description = $description;
        $this->updatedAt = $now;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner->id->equals($user->id);
    }
}
