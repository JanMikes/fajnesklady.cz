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
     * Admin-granted extension of the payment deadline (spec 086). While
     * today <= this date, dunning reminders, auto-charge/retry and the overdue
     * termination sweep are all suppressed; after it, the dunning ladder and
     * the termination countdown re-anchor to this date (see
     * {@see self::effectiveDunningAnchor()}). Deliberately orthogonal to
     * nextBillingDate so the billing period never drifts. Cleared by any
     * recorded payment.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $paymentGraceUntil = null;

    /**
     * When the customer was last notified about an upcoming recurring charge.
     * Used to (a) prevent duplicate ≥6-month-gap notices firing every cron run
     * inside the 7-day pre-charge window, and (b) record that an admin-triggered
     * parameter-change notice has been sent. Required by Podmínky čl. V.
     */
    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $lastAdvanceNoticeSentAt = null;

    /**
     * Per-contract recurring price in halere (CZK × 100) for ONE billing
     * period of this contract's cadence: a monthly figure on MONTHLY
     * contracts, a yearly figure on YEARLY contracts (upfront ONE_TIME totals
     * are never copied here — see OrderService::completeOrder). When set, this
     * overrides the current storage rate for ALL future recurring charges and
     * for any code projecting the locked-in price. Set during admin
     * onboarding (spec 025) for individual-price or free contracts.
     *
     *  null → use the storage's effective rate for the cadence (default)
     *  0    → free contract: skip charging, skip invoicing
     *  > 0  → custom per-period price that survives storage-price changes
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
        // A real payment landing during an admin extension makes the extension
        // moot (spec 086) — clear it so the next cycle dunning uses nextBillingDate.
        $this->paymentGraceUntil = null;
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

        // VOP XI compliance: day 0 initial fail, day 3 first retry, day 7 final retry
        // (termination is handled by app:terminate-overdue-contracts)
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
        // Two anchor conventions coexist: token-holding (AUTO) cycles keep
        // nextBillingDate == paidThroughDate, while tokenless anchors (external
        // prepayment, manual track) mean "paid THROUGH that day inclusive" —
        // billing resumes the day after (see markExternallyPrepaid()), or the
        // customer would be billed for a day they already paid.
        if ($this->billingMode->isRecurring() && null === $this->nextBillingDate && !$this->isFree()) {
            $this->nextBillingDate = null === $this->goPayParentPaymentId
                ? ($this->paidThroughDate?->modify('+1 day') ?? $previousEndDate)
                : ($this->paidThroughDate ?? $previousEndDate);
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

        // The legal cap binds single recurring CARD charges (Podmínky
        // opakovaných plateb čl. III). Yearly contracts are always manual
        // bank-transfer billing (never a GoPay ON_DEMAND charge) and their
        // individual amount is a per-YEAR figure, so the cap does not apply.
        if (null !== $amount && $amount > PriceCalculator::MAX_RECURRING_PAYMENT_AMOUNT_IN_HALER
            && PaymentFrequency::YEARLY !== $this->paymentFrequency) {
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
            // On a YEARLY contract the individual amount is a per-year figure;
            // callers of this method want a monthly-equivalent (outstanding-debt
            // proration, e-mail displays, landlord contract lists).
            return PaymentFrequency::YEARLY === $this->paymentFrequency
                ? intdiv($this->individualMonthlyAmount, 12)
                : $this->individualMonthlyAmount;
        }

        return $this->isLongTermMonthly()
            ? $this->storage->getEffectivePricePerMonthLongTerm()
            : $this->storage->getEffectivePricePerMonth();
    }

    public function isInBillingGrace(): bool
    {
        return match ($this->billingMode) {
            BillingMode::AUTO_RECURRING => null !== $this->goPayParentPaymentId,
            BillingMode::MANUAL_RECURRING => null !== $this->nextBillingDate || $this->failedBillingAttempts > 0,
            // Spec 078 tranches: an upfront contract with an unpaid tranche
            // (live anchor / overdue chase) gets the same grace as MANUAL.
            // ≤ 12-month upfront contracts never have an anchor → no grace.
            BillingMode::ONE_TIME => null !== $this->nextBillingDate || $this->failedBillingAttempts > 0,
        };
    }

    /**
     * Whether this contract's outstanding payments are collected by the manual
     * bank-transfer machinery (spec 036: payment-request e-mails + QR + FIO
     * reconciliation): every MANUAL_RECURRING contract, plus upfront ONE_TIME
     * contracts longer than 12 months whose remaining yearly tranches keep a
     * billing anchor (spec 078 tranches). Fully-paid upfront contracts and
     * ≤ 12-month upfront rentals (anchor NULL) are outside every billing cron.
     */
    public function usesManualBillingTrack(): bool
    {
        if (BillingMode::MANUAL_RECURRING === $this->billingMode) {
            return true;
        }

        return BillingMode::ONE_TIME === $this->billingMode && null !== $this->nextBillingDate;
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
        // Spec 078 tranches: an upfront (ONE_TIME) contract with a billing
        // anchor pays the rest of the rental in yearly tranches. Prolongation
        // flips billingMode to MANUAL_RECURRING (spec 077), so extension
        // cycles fall back to the monthly cadence below.
        if (BillingMode::ONE_TIME === $this->billingMode) {
            return '+1 year';
        }

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
     * Recurring amount in halere for one billing period. For MONTHLY this is
     * {@see self::getEffectiveMonthlyAmount()}; for YEARLY the individual
     * amount (a per-year figure when set — admin onboarding custom pricing)
     * wins over the storage's effective yearly rate.
     */
    public function getEffectiveRecurringAmount(): int
    {
        return PaymentFrequency::YEARLY === $this->paymentFrequency
            ? $this->individualMonthlyAmount ?? $this->storage->getEffectivePricePerYear()
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
        $resumesOn = $paidThroughDate->modify('+1 day');
        // Prepayment covering the whole term leaves no anchor: nothing to bill,
        // nothing for the manual cron to request, nothing for the overdue sweep.
        $this->nextBillingDate = $resumesOn > $this->endDate ? null : $resumesOn;
    }

    /**
     * True while an admin-granted payment extension (spec 086) is still in
     * effect: dunning, auto-charge/retry and overdue termination are all
     * suppressed until (and including) $paymentGraceUntil.
     */
    public function isInPaymentGrace(\DateTimeImmutable $now): bool
    {
        return null !== $this->paymentGraceUntil
            && $now->setTime(0, 0, 0) <= $this->paymentGraceUntil->setTime(0, 0, 0);
    }

    /**
     * The date the dunning ladder and the termination countdown are measured
     * from. An active or lapsed extension re-anchors both to the extended date
     * (spec 086); otherwise the raw billing anchor is used. Never affects the
     * billing period itself (paidThroughDate / next-period computation).
     */
    public function effectiveDunningAnchor(): ?\DateTimeImmutable
    {
        return $this->paymentGraceUntil ?? $this->nextBillingDate;
    }

    /**
     * Extend the payment deadline (spec 086). Sets paymentGraceUntil only —
     * nextBillingDate/paidThroughDate stay put so future cycles never drift.
     */
    public function extendPaymentDeadline(\DateTimeImmutable $newDeadline, \DateTimeImmutable $now): void
    {
        if ($this->isTerminated()) {
            throw new \DomainException('Cannot extend a terminated contract.');
        }

        if (null === $this->nextBillingDate) {
            throw new \DomainException('Nothing is due — no deadline to extend.');
        }

        $floor = $this->effectiveDunningAnchor();
        if (null !== $floor && $newDeadline->setTime(0, 0, 0) <= $floor->setTime(0, 0, 0)) {
            throw new \DomainException('New deadline must be after the current due date.');
        }

        if ($newDeadline->setTime(0, 0, 0) <= $now->setTime(0, 0, 0)) {
            throw new \DomainException('New deadline must be in the future.');
        }

        $this->paymentGraceUntil = $newDeadline;
    }

    /**
     * Record an off-system payment (cash / other bank account) covering the
     * rental through $paidThroughDate (spec 086). Advances the anchor like a
     * real charge and clears every dunning flag, including any active grace.
     */
    public function recordExternalPayment(\DateTimeImmutable $paidThroughDate, \DateTimeImmutable $now): void
    {
        $this->lastBilledAt = $now;
        $this->paidThroughDate = $paidThroughDate;
        $resumesOn = $paidThroughDate->modify('+1 day');
        $this->nextBillingDate = $resumesOn > $this->getEffectiveEndDate() ? null : $resumesOn;
        $this->failedBillingAttempts = 0;
        $this->lastBillingFailedAt = null;
        $this->pendingRecurringPaymentId = null;
        $this->paymentDemandSentAt = null;
        $this->paymentGraceUntil = null;
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
        // Same for upfront ONE_TIME contracts (spec 078 tranches): their
        // paidThroughDate tracks paid tranches, not an external prepayment.
        if (in_array($this->paymentFrequency, [PaymentFrequency::YEARLY, PaymentFrequency::ONE_TIME], true)) {
            return null;
        }

        $today = $now->setTime(0, 0, 0);
        $end = $this->paidThroughDate->setTime(0, 0, 0);

        return (int) $today->diff($end)->format('%r%a');
    }
}
