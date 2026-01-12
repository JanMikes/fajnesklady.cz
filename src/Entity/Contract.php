<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RentalType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class Contract
{
    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $documentPath = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $signedAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $terminatedAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\OneToOne(targetEntity: Order::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Order $order,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) User $user,
        #[ORM\ManyToOne(targetEntity: Storage::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Storage $storage,
        #[ORM\Column(length: 20, enumType: RentalType::class)]
        private(set) RentalType $rentalType,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $startDate,
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private(set) ?\DateTimeImmutable $endDate,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
    }

    public function sign(\DateTimeImmutable $now): void
    {
        $this->signedAt = $now;
    }

    public function terminate(\DateTimeImmutable $now): void
    {
        $this->terminatedAt = $now;
        $this->storage->release($now);
    }

    public function attachDocument(string $path, \DateTimeImmutable $now): void
    {
        $this->documentPath = $path;
    }

    public function isActive(\DateTimeImmutable $now): bool
    {
        if (null !== $this->terminatedAt) {
            return false;
        }

        if (null !== $this->endDate && $now > $this->endDate) {
            return false;
        }

        return true;
    }

    public function isSigned(): bool
    {
        return null !== $this->signedAt;
    }

    public function isTerminated(): bool
    {
        return null !== $this->terminatedAt;
    }

    public function isUnlimited(): bool
    {
        return RentalType::UNLIMITED === $this->rentalType;
    }

    public function hasDocument(): bool
    {
        return null !== $this->documentPath;
    }
}
