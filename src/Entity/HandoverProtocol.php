<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\HandoverStatus;
use App\Event\HandoverCompleted;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class HandoverProtocol implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Column(length: 30, enumType: HandoverStatus::class)]
    public private(set) HandoverStatus $status;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $tenantComment = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $tenantCompletedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public private(set) ?string $landlordComment = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $landlordCompletedAt = null;

    #[ORM\Column(length: 50, nullable: true)]
    public private(set) ?string $newLockCode = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    public private(set) int $remindersSentCount = 0;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $lastReminderSentAt = null;

    /** @var Collection<int, HandoverPhoto> */
    #[ORM\OneToMany(targetEntity: HandoverPhoto::class, mappedBy: 'handoverProtocol', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $photos;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\OneToOne(targetEntity: Contract::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Contract $contract,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->status = HandoverStatus::PENDING;
        $this->photos = new ArrayCollection();
    }

    public function completeTenantSide(string $comment, \DateTimeImmutable $now): void
    {
        if (null !== $this->tenantCompletedAt) {
            throw new \DomainException('Nájemce již předávací protokol vyplnil.');
        }

        $this->tenantComment = $comment;
        $this->tenantCompletedAt = $now;

        if (null !== $this->landlordCompletedAt) {
            $this->markCompleted($now);
        } else {
            $this->status = HandoverStatus::TENANT_COMPLETED;
        }
    }

    public function completeLandlordSide(string $comment, ?string $newLockCode, \DateTimeImmutable $now): void
    {
        if (null !== $this->landlordCompletedAt) {
            throw new \DomainException('Pronajímatel již předávací protokol vyplnil.');
        }

        $this->landlordComment = $comment;
        $this->landlordCompletedAt = $now;
        $this->newLockCode = $newLockCode;

        if (null !== $this->tenantCompletedAt) {
            $this->markCompleted($now);
        } else {
            $this->status = HandoverStatus::LANDLORD_COMPLETED;
        }
    }

    public function isFullyCompleted(): bool
    {
        return $this->status->isCompleted();
    }

    public function needsTenantCompletion(): bool
    {
        return null === $this->tenantCompletedAt;
    }

    public function needsLandlordCompletion(): bool
    {
        return null === $this->landlordCompletedAt;
    }

    public function recordReminderSent(\DateTimeImmutable $now): void
    {
        ++$this->remindersSentCount;
        $this->lastReminderSentAt = $now;
    }

    /**
     * @return Collection<int, HandoverPhoto>
     */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    /**
     * @return Collection<int, HandoverPhoto>
     */
    public function getTenantPhotos(): Collection
    {
        return $this->photos->filter(fn (HandoverPhoto $photo) => 'tenant' === $photo->uploadedBy);
    }

    /**
     * @return Collection<int, HandoverPhoto>
     */
    public function getLandlordPhotos(): Collection
    {
        return $this->photos->filter(fn (HandoverPhoto $photo) => 'landlord' === $photo->uploadedBy);
    }

    public function addPhoto(HandoverPhoto $photo): void
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
        }
    }

    public function removePhoto(HandoverPhoto $photo): void
    {
        $this->photos->removeElement($photo);
    }

    private function markCompleted(\DateTimeImmutable $now): void
    {
        $this->status = HandoverStatus::COMPLETED;
        $this->completedAt = $now;

        $this->recordThat(new HandoverCompleted(
            handoverProtocolId: $this->id,
            contractId: $this->contract->id,
            newLockCode: $this->newLockCode,
            occurredOn: $now,
        ));
    }
}
