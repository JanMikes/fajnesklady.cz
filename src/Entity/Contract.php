<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RentalType;
use App\Enum\TerminationReason;
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

    #[ORM\Column(length: 20, nullable: true, enumType: TerminationReason::class)]
    public private(set) ?TerminationReason $terminationReason = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $outstandingDebtAmount = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?string $goPayParentPaymentId = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $nextBillingDate = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $lastBilledAt = null;

    #[ORM\Column(options: ['default' => 0])]
    public private(set) int $failedBillingAttempts = 0;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $lastBillingFailedAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $terminationNoticedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $terminatesAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $paidThroughDate = null;

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

    public function terminate(\DateTimeImmutable $now, TerminationReason $reason = TerminationReason::EXPIRED): void
    {
        $this->terminatedAt = $now;
        $this->terminationReason = $reason;
        $this->storage->release($now);
    }

    public function setOutstandingDebt(int $amount): void
    {
        $this->outstandingDebtAmount = $amount;
    }

    public function hasOutstandingDebt(): bool
    {
        return null !== $this->outstandingDebtAmount && $this->outstandingDebtAmount > 0;
    }

    public function isTerminatedDueToPaymentFailure(): bool
    {
        return TerminationReason::PAYMENT_FAILURE === $this->terminationReason;
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

    public function setRecurringPayment(string $parentPaymentId, ?\DateTimeImmutable $nextBillingDate, \DateTimeImmutable $paidThroughDate): void
    {
        $this->goPayParentPaymentId = $parentPaymentId;
        $this->nextBillingDate = $nextBillingDate;
        $this->paidThroughDate = $paidThroughDate;
    }

    public function recordBillingCharge(\DateTimeImmutable $chargedAt, ?\DateTimeImmutable $nextBillingDate, \DateTimeImmutable $paidThroughDate): void
    {
        $this->lastBilledAt = $chargedAt;
        $this->nextBillingDate = $nextBillingDate;
        $this->paidThroughDate = $paidThroughDate;
        $this->failedBillingAttempts = 0;
        $this->lastBillingFailedAt = null;
    }

    public function recordFailedBillingAttempt(\DateTimeImmutable $failedAt): void
    {
        ++$this->failedBillingAttempts;
        $this->lastBillingFailedAt = $failedAt;
    }

    public function cancelRecurringPayment(): void
    {
        $this->goPayParentPaymentId = null;
        $this->nextBillingDate = null;
    }

    public function hasActiveRecurringPayment(): bool
    {
        return null !== $this->goPayParentPaymentId && !$this->isTerminated();
    }

    public function isDueBilling(\DateTimeImmutable $now): bool
    {
        return $this->hasActiveRecurringPayment()
            && null !== $this->nextBillingDate
            && $now >= $this->nextBillingDate
            && 0 === $this->failedBillingAttempts;
    }

    public function needsRetry(\DateTimeImmutable $now): bool
    {
        if (!$this->hasActiveRecurringPayment()) {
            return false;
        }

        if (null === $this->lastBillingFailedAt) {
            return false;
        }

        return match ($this->failedBillingAttempts) {
            1 => $now >= $this->lastBillingFailedAt->modify('+3 days'),
            2 => $now >= $this->lastBillingFailedAt->modify('+7 days'),
            default => false,
        };
    }

    public function requestTermination(\DateTimeImmutable $noticedAt, \DateTimeImmutable $terminatesAt): void
    {
        if ($this->isTerminated()) {
            throw new \DomainException('Contract is already terminated.');
        }

        if ($this->hasPendingTermination()) {
            throw new \DomainException('Termination notice has already been submitted.');
        }

        $this->terminationNoticedAt = $noticedAt;
        $this->terminatesAt = $terminatesAt;
    }

    public function hasPendingTermination(): bool
    {
        return null !== $this->terminatesAt && null === $this->terminatedAt;
    }

    public function isTerminationDue(\DateTimeImmutable $now): bool
    {
        return $this->hasPendingTermination() && $now >= $this->terminatesAt;
    }

    public function getEffectiveEndDate(): ?\DateTimeImmutable
    {
        return $this->terminatesAt ?? $this->endDate;
    }
}
