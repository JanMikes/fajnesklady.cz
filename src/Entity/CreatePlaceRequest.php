<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RequestStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class CreatePlaceRequest
{
    #[ORM\Column(length: 30, enumType: RequestStatus::class)]
    public private(set) RequestStatus $status;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $processedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public private(set) ?User $processedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $adminNote = null;

    #[ORM\ManyToOne(targetEntity: Place::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public private(set) ?Place $createdPlace = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) User $requestedBy,
        #[ORM\Column(length: 255)]
        private(set) string $name,
        #[ORM\Column(length: 500)]
        private(set) string $address,
        #[ORM\Column(length: 100)]
        private(set) string $city,
        #[ORM\Column(length: 20)]
        private(set) string $postalCode,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private(set) ?string $description,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->status = RequestStatus::PENDING;
    }

    public function approve(Place $createdPlace, User $processedBy, \DateTimeImmutable $now): void
    {
        $this->status = RequestStatus::APPROVED;
        $this->createdPlace = $createdPlace;
        $this->processedBy = $processedBy;
        $this->processedAt = $now;
    }

    public function reject(?string $adminNote, User $processedBy, \DateTimeImmutable $now): void
    {
        $this->status = RequestStatus::REJECTED;
        $this->adminNote = $adminNote;
        $this->processedBy = $processedBy;
        $this->processedAt = $now;
    }

    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    public function isProcessed(): bool
    {
        return $this->status->isTerminal();
    }
}
