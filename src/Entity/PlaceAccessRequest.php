<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PlaceAccessRequestStatus;
use App\Event\PlaceAccessRequestApproved;
use App\Event\PlaceAccessRequestDenied;
use App\Event\PlaceAccessRequested;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class PlaceAccessRequest implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Column(length: 20)]
    public private(set) PlaceAccessRequestStatus $status;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public private(set) ?User $reviewedBy = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $reviewedAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Place::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) Place $place,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) User $requestedBy,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private(set) ?string $message,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->status = PlaceAccessRequestStatus::PENDING;

        $this->recordThat(new PlaceAccessRequested(
            requestId: $this->id,
            placeId: $this->place->id,
            requestedById: $this->requestedBy->id,
            occurredOn: $this->createdAt,
        ));
    }

    public function approve(User $reviewedBy, \DateTimeImmutable $now): void
    {
        $this->status = PlaceAccessRequestStatus::APPROVED;
        $this->reviewedBy = $reviewedBy;
        $this->reviewedAt = $now;

        $this->recordThat(new PlaceAccessRequestApproved(
            requestId: $this->id,
            placeId: $this->place->id,
            landlordId: $this->requestedBy->id,
            occurredOn: $now,
        ));
    }

    public function deny(User $reviewedBy, \DateTimeImmutable $now): void
    {
        $this->status = PlaceAccessRequestStatus::DENIED;
        $this->reviewedBy = $reviewedBy;
        $this->reviewedAt = $now;

        $this->recordThat(new PlaceAccessRequestDenied(
            requestId: $this->id,
            placeId: $this->place->id,
            landlordId: $this->requestedBy->id,
            occurredOn: $now,
        ));
    }
}
