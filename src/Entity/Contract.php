<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\TerminationReason;
use App\Event\ContractPriceChanged;
use App\Event\ContractProlonged;
use App\Service\PriceCalculator;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class Contract implements EntityWithEvents
{
    use HasEvents;

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

    /**
     * GoPay payment ID of a recurring charge that is still being processed
     * asynchronously by GoPay (synchronous polling timed out, webhook hasn't
     * arrived yet). When set, the next cron run reconciles this payment's
     * status with GoPay before issuing a new charge — protects against
     * double-charges when the webhook is slow or fails to deliver.
     */
    #[ORM\Column(nullable: true)]
    public private(set) ?string $pendingRecurringPaymentId = null;

    /**
     * GoPay payment ID of an in-flight bank→card switch during prolongation
     * (spec 077). The customer initiated an ON_DEMAND setup charge; the
     * webhook flips the contract to AUTO_RECURRING once it is PAID and clears
     * this again (also cleared on a terminal CANCELED/TIMEOUTED status).
     */
    #[ORM\Column(nullable: true)]
    public private(set) ?string $pendingCardSetupPaymentId = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $terminationNoticedAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $terminatesAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $paymentDemandSentAt = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $paidThroughDate = null;

    /**
     * When the customer was last notified about an upcoming recurring charge.
     * Used to (a) prevent duplicate ≥6-month-gap notices firing every cron run
     * inside the 7-day pre-charge window, and (b) record that an admin-triggered
     * parameter-change notice has been sent. Required by Podmínky čl. V.
     */
    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $lastAdvanceNoticeSentAt = null;

    /**
     * Per-contract monthly recurring price in halere (CZK × 100). When set, this
     * overrides the current storage rate for ALL future recurring charges and
     * for any code projecting the "locked-in monthly". Set during admin
     * onboarding (spec 025) for individual-price or free contracts.
     *
     *  null → use storage.effectivePricePerMonth (default behaviour)
     *  0    → free contract: skip charging, skip invoicing
     *  > 0  → custom monthly that survives storage-price changes
     */
    #[ORM\Column(nullable: true)]
    public private(set) ?int $individualMonthlyAmount = null;

    /**
     * Mirrors {@see Order::$billingMode}. Locked at order completion (carried
     * from Order by {@see \App\Service\OrderService::completeOrder()}). Drives
     * SendManualBillingPaymentRequestsCommand candidate selection + MRR /
     * active-recurring predicates across ContractRepository.
     */
    #[ORM\Column(length: 20, enumType: BillingMode::class, options: ['default' => 'auto_recurring'])]
    public private(set) BillingMode $billingMode = BillingMode::AUTO_RECURRING;

    /**
     * Mirrors {@see Order::$paymentFrequency}. Locked at order completion;
     * never mutated thereafter. Drives the billing cadence anchor in both
     * recurring crons via {@see self::getBillingCadenceStep()} — MONTHLY ->
     * `+1 month`, YEARLY -> `+1 year`. Yearly contracts are always
     * {@see BillingMode::MANUAL_RECURRING} by design (sidesteps the GoPay
     * 15 000 Kč ON_DEMAND cap; see spec 045).
     */
    #[ORM\Column(length: 20, enumType: PaymentFrequency::class, options: ['default' => 'monthly'])]
    public private(set) PaymentFrequency $paymentFrequency = PaymentFrequency::MONTHLY;

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
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $startDate,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $endDate,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
    }

    public function sign(\DateTimeImmutable $now): void
    {
        $this->signedAt = $now;
    }

    public function terminate(\DateTimeImmutable $now, TerminationReason $reason = TerminationReason::EXPIRED, bool $releaseStorage = true): void
    {
        $this->terminatedAt = $now;
        $this->terminationReason = $reason;

        if ($releaseStorage) {
            $this->storage->release($now);
        }
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

        if ($now > $this->endDate) {
            // VOP §IV grace: recurring contracts stay active past endDate
            // while the billing/retry cycle is in progress
            return $this->isInBillingGrace();
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

    /**
     * The spec-076 availability guarantee: a live-token card-recurring contract
     * blocks its storage open-endedly, so the customer can always prolong.
     * Cancelling the recurring payment (or a pending termination) forfeits it.
     */
    public function hasAvailabilityGuarantee(): bool
    {
        return BillingMode::AUTO_RECURRING === $this->billingMode
            && null !== $this->goPayParentPaymentId
            && !$this->isTerminated()
            && !$this->hasPendingTermination();
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
        $this->pendingRecurringPaymentId = null;
        $this->paymentDemandSentAt = null;
    }

    public function recordFailedBillingAttempt(\DateTimeImmutable $failedAt): void
    {
        ++$this->failedBillingAttempts;
        $this->lastBillingFailedAt = $failedAt;
        $this->pendingRecurringPaymentId = null;
    }

    /**
     * Mark a freshly-issued GoPay charge as still being processed asynchronously.
     * Set when synchronous polling timed out — the webhook (or the next cron run)
     * is expected to reconcile the final state.
     */
    public function recordInFlightCharge(string $paymentId): void
    {
        $this->pendingRecurringPaymentId = $paymentId;
    }

    public function clearPendingRecurringCharge(): void
    {
        $this->pendingRecurringPaymentId = null;
    }

    public function hasPendingRecurringCharge(): bool
    {
        return null !== $this->pendingRecurringPaymentId;
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

        // VOP XI compliance: day 0 initial fail, day 3 first retry, day 7 second retry + terminate
        return match ($this->failedBillingAttempts) {
            1 => $now >= $this->lastBillingFailedAt->modify('+3 days'),
            2 => $now >= $this->lastBillingFailedAt->modify('+4 days'),
            default => false,
        };
    }

    /**
     * Spec 077: prolongation is the ONLY way a contract continues past its
     * end date (no auto-extension exists). Moves the end date and resumes the
     * billing schedule when the final cycle had already closed it.
     */
    public function prolong(\DateTimeImmutable $newEndDate, ?User $prolongedBy, \DateTimeImmutable $now): void
    {
        if ($this->isTerminated()) {
            throw new \DomainException('Cannot prolong a terminated contract.');
        }

        if ($this->hasPendingTermination()) {
            throw new \DomainException('Cannot prolong a contract with a pending termination.');
        }

        if ($newEndDate <= $this->endDate) {
            throw new \DomainException('New end date must be after the current end date.');
        }

        $previousEndDate = $this->endDate;
        $this->endDate = $newEndDate;

        // Resume billing when the final (prorated) cycle already closed the
        // schedule; mid-term prolongations keep their running cadence and the
        // amount calculator prorates against the new end automatically.
        if ($this->billingMode->isRecurring() && null === $this->nextBillingDate && !$this->isFree()) {
            $this->nextBillingDate = $this->paidThroughDate ?? $previousEndDate;
        }

        $this->recordThat(new ContractProlonged(
            contractId: $this->id,
            previousEndDate: $previousEndDate,
            newEndDate: $newEndDate,
            prolongedBy: $prolongedBy,
            occurredOn: $now,
        ));
    }

    /**
     * Re-anchor the billing schedule without recording a charge — used by
     * prolongation when a contract joins or continues the manual track
     * (spec 077). {@see self::recordBillingCharge()} stays reserved for
     * actual payments.
     */
    public function scheduleNextBilling(\DateTimeImmutable $nextBillingDate, ?\DateTimeImmutable $paidThroughDate): void
    {
        $this->nextBillingDate = $nextBillingDate;
        if (null !== $paidThroughDate) {
            $this->paidThroughDate = $paidThroughDate;
        }
    }

    /**
     * Track the in-flight bank→card switch payment (spec 077); resolved by
     * the GoPay webhook via {@see self::completeCardSetup()} or cleared on a
     * terminal non-paid status.
     */
    public function startCardSetup(string $paymentId): void
    {
        $this->pendingCardSetupPaymentId = $paymentId;
    }

    public function completeCardSetup(string $parentPaymentId, ?\DateTimeImmutable $nextBillingDate, \DateTimeImmutable $paidThroughDate): void
    {
        $this->billingMode = BillingMode::AUTO_RECURRING;
        $this->pendingCardSetupPaymentId = null;
        $this->setRecurringPayment($parentPaymentId, $nextBillingDate, $paidThroughDate);
    }

    public function clearCardSetup(): void
    {
        $this->pendingCardSetupPaymentId = null;
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

    public function getEffectiveEndDate(): \DateTimeImmutable
    {
        return $this->terminatesAt ?? $this->endDate;
    }

    public function recordAdvanceNoticeSent(\DateTimeImmutable $now): void
    {
        $this->lastAdvanceNoticeSentAt = $now;
    }

    public function recordPaymentDemandSent(\DateTimeImmutable $now): void
    {
        $this->paymentDemandSentAt = $now;
    }

    public function applyIndividualMonthlyAmount(
        ?int $amount,
        ?User $changedBy,
        ?string $reason,
        \DateTimeImmutable $now,
    ): void {
        if (null !== $amount && $amount < 0) {
            throw new \InvalidArgumentException('Individual monthly amount cannot be negative.');
        }

        if (null !== $amount && $amount > PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER) {
            throw new \DomainException(sprintf('Individual monthly amount %d Kč exceeds the legal recurring-payment maximum of %d Kč.', intdiv($amount, 100), intdiv(PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER, 100)));
        }

        $previous = $this->individualMonthlyAmount;
        $this->individualMonthlyAmount = $amount;

        $this->recordThat(new ContractPriceChanged(
            contractId: $this->id,
            previousAmount: $previous,
            newAmount: $amount,
            changedBy: $changedBy,
            reason: $reason,
            occurredOn: $now,
        ));
    }

    public function getEffectiveMonthlyAmount(): int
    {
        if (null !== $this->individualMonthlyAmount) {
            return $this->individualMonthlyAmount;
        }

        return $this->isLongTermMonthly()
            ? $this->storage->getEffectivePricePerMonthLongTerm()
            : $this->storage->getEffectivePricePerMonth();
    }

    public function isInBillingGrace(): bool
    {
        if (!$this->billingMode->isRecurring()) {
            return false;
        }

        return match ($this->billingMode) {
            BillingMode::AUTO_RECURRING => null !== $this->goPayParentPaymentId,
            BillingMode::MANUAL_RECURRING => null !== $this->nextBillingDate || $this->failedBillingAttempts > 0,
            default => false,
        };
    }

    private function isLongTermMonthly(): bool
    {
        return (int) $this->startDate->diff($this->endDate)->days >= PriceCalculator::SHORT_TERM_THRESHOLD_DAYS;
    }

    public function applyBillingMode(BillingMode $mode): void
    {
        $this->billingMode = $mode;
    }

    public function applyPaymentFrequency(PaymentFrequency $frequency): void
    {
        $this->paymentFrequency = $frequency;
    }

    /**
     * Modifier string the recurring crons feed to `\DateTimeImmutable::modify()`
     * to advance `nextBillingDate` and `paidThroughDate` after a successful
     * charge. Shared by both AUTO and MANUAL paths so the rule lives in one
     * place — see spec 045 §10.
     */
    public function getBillingCadenceStep(): string
    {
        return PaymentFrequency::YEARLY === $this->paymentFrequency ? '+1 year' : '+1 month';
    }

    /**
     * Number of days in one billing period. Used by amount-proration paths
     * (last-cycle prorate) so yearly contracts measure against 365 days
     * instead of 30. Mirrors {@see PriceCalculator}'s 30-day-month convention
     * for MONTHLY.
     */
    public function getBillingPeriodDays(): int
    {
        return PaymentFrequency::YEARLY === $this->paymentFrequency ? 365 : 30;
    }

    /**
     * Recurring amount in halere. For MONTHLY this is {@see self::getEffectiveMonthlyAmount()};
     * for YEARLY it reads the storage's effective yearly rate (no per-customer
     * yearly override is supported today — admin onboarding's "individual price"
     * remains a monthly figure).
     */
    public function getEffectiveRecurringAmount(): int
    {
        return PaymentFrequency::YEARLY === $this->paymentFrequency
            ? $this->storage->getEffectivePricePerYear()
            : $this->getEffectiveMonthlyAmount();
    }

    public function isYearly(): bool
    {
        return PaymentFrequency::YEARLY === $this->paymentFrequency;
    }

    public function hasIndividualPrice(): bool
    {
        return null !== $this->individualMonthlyAmount;
    }

    public function isFree(): bool
    {
        return 0 === $this->individualMonthlyAmount;
    }

    /**
     * Set up an externally-prepaid contract: paidThroughDate is the last day
     * of prepayment, nextBillingDate is the day after that. No goPay token is
     * established here — the customer must convert via the post-prepayment
     * flow (spec 026, deferred).
     */
    public function markExternallyPrepaid(\DateTimeImmutable $paidThroughDate): void
    {
        $this->paidThroughDate = $paidThroughDate;
        $this->nextBillingDate = $paidThroughDate->modify('+1 day');
    }

    /**
     * Calendar days from $now to $this->paidThroughDate for an externally-
     * prepaid contract. Returns null when this contract is NOT in the
     * "externally prepaid, not yet converted" state — i.e. when:
     *   - paidThroughDate is null (no prepayment recorded), OR
     *   - goPayParentPaymentId is set (customer already converted to GoPay), OR
     *   - the contract is terminated.
     *
     * Returns a negative integer when the prepayment has already lapsed —
     * the customer-facing partial uses 0..7 as the "ending soon" band and
     * treats >7 as "future" / <0 as "lapsed, hide".
     */
    public function daysUntilExternalPrepaymentEnds(\DateTimeImmutable $now): ?int
    {
        if (null === $this->paidThroughDate) {
            return null;
        }
        if (null !== $this->goPayParentPaymentId) {
            return null;
        }
        if ($this->isTerminated()) {
            return null;
        }
        // YEARLY contracts also have paidThroughDate populated (set after each
        // yearly charge) but they're not "externally prepaid" — they're billed
        // annually via the manual cron. Don't surface the prepayment banner.
        if (PaymentFrequency::YEARLY === $this->paymentFrequency) {
            return null;
        }

        $today = $now->setTime(0, 0, 0);
        $end = $this->paidThroughDate->setTime(0, 0, 0);

        return (int) $today->diff($end)->format('%r%a');
    }
}
