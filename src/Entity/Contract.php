<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\TerminationReason;
use App\Event\ContractPriceChanged;
use App\Event\ContractProlonged;
use App\Service\Billing\BillingCalendar;
use App\Service\PriceCalculator;
use App\Value\BillingCycle;
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

    /**
     * Day of month (1–31) the billing schedule is anchored to. Kept separately
     * from nextBillingDate because a month-end clamp (31.08 → 30.09) must not
     * lose the anchor — the next cycle returns to the 31st. Maintained by
     * {@see self::assignNextBillingDate()}; null on contracts anchored before
     * this existed, which then fall back to the current anchor's day.
     */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    public private(set) ?int $billingAnchorDay = null;

    /**
     * Two-walk billing (2026-11-25). Rental PERIODS follow the contract start
     * (the schedule the customer was quoted); DUE dates follow the first
     * payment's day of month — the recurring consent's "vždy ke stejnému dni v
     * měsíci jako první platba", applied to every payment type. In this mode:
     *  - nextBillingDate = due date of the next unpaid period (every cron,
     *    reminder, dunning and overdue query keeps reading it as a due date);
     *  - nextPeriodStart = first day of that period (prices it, see
     *    {@see self::nextPeriodStart()});
     *  - billingAnchorDay = the first payment's day of month;
     *  - periodAnchorDay = the period walk's day (contract start day);
     *  - chargeMonthOffset = due month relative to the period month: 1 = the
     *    month before (paid early across a month boundary), 0 = same month,
     *    -1 = the month after (paid late across a month boundary).
     * All three are null in single-walk mode (legacy contracts, admin
     * prepayments, prolongation re-anchors): the period starts on its due date.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $nextPeriodStart = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    public private(set) ?int $periodAnchorDay = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    public private(set) ?int $chargeMonthOffset = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $lastBilledAt = null;

    #[ORM\Column(options: ['default' => 0])]
    public private(set) int $failedBillingAttempts = 0;

    /**
     * Money received but not yet consumed by an obligation, in haléře (spec 091).
     * Fed by over-payments and by under-payments that could not complete a cycle;
     * drained by the next allocation before any new money is considered, and
     * subtracted from what we ask the customer for
     * ({@see \App\Service\Billing\RecurringAmountCalculator::amountToRequest()}).
     */
    #[ORM\Column(options: ['default' => 0])]
    public private(set) int $creditBalance = 0;

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

    /**
     * Reduce a recorded post-termination debt by an exact amount (admin settle
     * or waive). Clears the field to null once fully covered so
     * hasOutstandingDebt(), the overdue predicate and the payment-overview debt
     * row all drop off in lockstep.
     */
    public function reduceOutstandingDebt(int $amountInHaler): void
    {
        if (null === $this->outstandingDebtAmount || $this->outstandingDebtAmount <= 0) {
            throw new \DomainException('Contract has no outstanding debt to reduce.');
        }
        if ($amountInHaler <= 0) {
            throw new \InvalidArgumentException('Reduction amount must be positive.');
        }
        if ($amountInHaler > $this->outstandingDebtAmount) {
            throw new \DomainException('Reduction exceeds the outstanding debt.');
        }

        $remaining = $this->outstandingDebtAmount - $amountInHaler;
        $this->outstandingDebtAmount = $remaining > 0 ? $remaining : null;
    }

    public function hasOutstandingDebt(): bool
    {
        return null !== $this->outstandingDebtAmount && $this->outstandingDebtAmount > 0;
    }

    public function addCredit(int $amountInHaler): void
    {
        if ($amountInHaler <= 0) {
            throw new \InvalidArgumentException('Credit amount must be positive.');
        }

        $this->creditBalance += $amountInHaler;
    }

    /**
     * Take up to $amountInHaler out of the credit balance and report what was
     * actually taken. Clamped to the available balance so callers never have to
     * guard, and a zero/negative request is simply a no-op — the allocator asks
     * for whatever an obligation needs without knowing what is on hand.
     */
    public function consumeCredit(int $amountInHaler): int
    {
        if ($amountInHaler <= 0) {
            return 0;
        }

        $consumed = min($amountInHaler, $this->creditBalance);
        $this->creditBalance -= $consumed;

        return $consumed;
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

    public function attachCardToken(string $parentPaymentId): void
    {
        $this->goPayParentPaymentId = $parentPaymentId;
    }

    public function setRecurringPayment(string $parentPaymentId, ?\DateTimeImmutable $nextBillingDate, \DateTimeImmutable $paidThroughDate): void
    {
        $this->goPayParentPaymentId = $parentPaymentId;
        $this->assignNextBillingDate($nextBillingDate);
        $this->paidThroughDate = $paidThroughDate;
    }

    public function recordBillingCharge(\DateTimeImmutable $chargedAt, ?\DateTimeImmutable $nextBillingDate, \DateTimeImmutable $paidThroughDate): void
    {
        $this->lastBilledAt = $chargedAt;
        $this->assignNextBillingDate($nextBillingDate);
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

    /**
     * The card retry ladder (attempts 1–3) no longer applies once billing
     * moved off the card — a leftover count would read as "nezaplatil výzvu
     * (1×)" on the bank-transfer track. The failure itself stays in the
     * audit log.
     */
    public function clearCardRetryLadder(): void
    {
        $this->failedBillingAttempts = 0;
        $this->lastBillingFailedAt = null;
    }

    public function cancelRecurringPayment(): void
    {
        $this->goPayParentPaymentId = null;
        $this->nextBillingDate = null;
        $this->clearPeriodWalk();
    }

    /**
     * Void the card token only — the billing anchors (due date AND period
     * walk) stay, so the unpaid remainder moves to the bank-transfer track
     * without shifting a single day ({@see \App\Service\Billing\CardToBankTransferSwitch}).
     */
    public function dropCardToken(): void
    {
        $this->goPayParentPaymentId = null;
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
        if ($this->billingMode->isRecurring() && null === $this->nextBillingDate && !$this->isFree()) {
            $this->assignNextBillingDate($this->resumeBillingAfterProlongation($previousEndDate));
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
     * Where billing resumes when a contract with no live anchor is prolonged.
     *
     * Paid exactly to the previous end — every customer-paid final cycle (card
     * or bank transfer, also after the card was cancelled) and the canonical
     * fully-prepaid state (see applyInclusivePaidThrough()) — the extension's
     * first cycle starts ON the previous end: the contract term is
     * [start, end), so that day was never paid. Before 2026-11 a tokenless
     * contract always resumed the day after, leaving the end day unbilled.
     *
     * Otherwise the token-holding (AUTO) convention keeps
     * nextBillingDate == paidThroughDate, and a tokenless admin date (paid
     * BEYOND the end, or a legacy partial value) is the inclusive last paid
     * day — billing resumes the day after.
     */
    private function resumeBillingAfterProlongation(\DateTimeImmutable $previousEndDate): \DateTimeImmutable
    {
        if (null === $this->paidThroughDate) {
            return $previousEndDate;
        }

        if (null !== $this->goPayParentPaymentId
            || $this->paidThroughDate->format('Y-m-d') === $previousEndDate->format('Y-m-d')) {
            return $this->paidThroughDate;
        }

        return $this->paidThroughDate->modify('+1 day');
    }

    /**
     * Re-anchor the billing schedule without recording a charge — used by
     * prolongation when a contract joins or continues the manual track
     * (spec 077). {@see self::recordBillingCharge()} stays reserved for
     * actual payments.
     */
    public function scheduleNextBilling(\DateTimeImmutable $nextBillingDate, ?\DateTimeImmutable $paidThroughDate): void
    {
        $this->assignNextBillingDate($nextBillingDate);
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

    /**
     * The PAID card-setup charge (spec 077) becomes the ON_DEMAND parent token
     * and pays the period the contract is currently due for. Card charges then
     * follow the setup payment's day of month — the setup page's consent says
     * "vždy ke stejnému dni v měsíci jako tato platba".
     *
     * @return BillingCycle the period the setup charge paid
     */
    public function completeCardSetup(string $parentPaymentId, \DateTimeImmutable $paidAt): BillingCycle
    {
        $this->billingMode = BillingMode::AUTO_RECURRING;
        $this->pendingCardSetupPaymentId = null;
        $this->goPayParentPaymentId = $parentPaymentId;

        $periodStart = $this->nextPeriodStart() ?? $paidAt->setTime(0, 0);
        $periodAnchorDay = $this->periodAnchorDay ?? $this->billingAnchorDay ?? (int) $periodStart->format('j');

        $cycle = $this->seedPeriodWalk($periodStart, $periodAnchorDay, $paidAt);
        $this->markCyclePaid($paidAt);

        return $cycle;
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

        $endDay = $terminatesAt->format('Y-m-d');
        $periodStart = $this->nextPeriodStart();
        if (null === $this->nextBillingDate || null === $periodStart) {
            return;
        }

        // The next unpaid period starts on/after the new end: it lies wholly
        // outside the rental — nothing is left to bill (an early payer giving
        // notice must never be charged for a period after the end).
        if ($periodStart->format('Y-m-d') >= $endDay) {
            $this->clearPeriodWalk();
            $this->nextBillingDate = null;
            // Canonical fully-paid state (an admin prepayment stores the
            // inclusive last day, one below the end) and no stale card retry.
            $end = $this->getEffectiveEndDate();
            if (null === $this->paidThroughDate || $this->paidThroughDate->format('Y-m-d') < $end->format('Y-m-d')) {
                $this->paidThroughDate = $end;
            }
            $this->failedBillingAttempts = 0;
            $this->lastBillingFailedAt = null;

            return;
        }

        // Two-walk billing: a late payer's due date follows its period start.
        // If the notice moves the end on/before that due date while the period
        // itself still starts before the end, re-derive the due date (it then
        // clamps to the period start) — otherwise the last, shortened period
        // would never be billed (the charge cron skips anything due after the
        // termination date). Compared by DAY: terminatesAt carries a time.
        if (null !== $this->nextPeriodStart && $this->nextBillingDate->format('Y-m-d') >= $endDay) {
            $this->nextBillingDate = $this->dueDateForPeriod($this->nextPeriodStart);
        }
    }

    public function hasPendingTermination(): bool
    {
        return null !== $this->terminatesAt && null === $this->terminatedAt;
    }

    public function isTerminationDue(\DateTimeImmutable $now): bool
    {
        return $this->hasPendingTermination() && $now >= $this->terminatesAt;
    }

    /**
     * When the rental actually ends: the earlier of the contract end and a
     * pending notice. A one-month notice given less than a month before the
     * end must not stretch the rental (and its billing) past the end date
     * (2026-11-25: notice on 25.11 → 25.12 for a contract ending 21.12).
     */
    public function getEffectiveEndDate(): \DateTimeImmutable
    {
        if (null !== $this->terminatesAt && $this->terminatesAt < $this->endDate) {
            return $this->terminatesAt;
        }

        return $this->endDate;
    }

    /**
     * Whether the move-out handover is due: the rental is over, or it ends
     * within {@see HandoverProtocol::WINDOW_DAYS_BEFORE_END} days. Outside this
     * window a handover protocol is meaningless — the tenant keeps the unit
     * (e.g. after a prolongation), so no reminders and no lock-code change.
     */
    public function isWithinHandoverWindow(\DateTimeImmutable $now): bool
    {
        if ($this->isTerminated()) {
            return true;
        }

        $windowEnd = $now->modify(sprintf('+%d days', HandoverProtocol::WINDOW_DAYS_BEFORE_END));

        return $this->getEffectiveEndDate() <= $windowEnd;
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
    /**
     * The billing date one cadence step after $from — +1 month (MONTHLY) or
     * +12 months (YEARLY / upfront tranches) — keeping this contract's anchor
     * day across short months. Use this, never `modify('+1 month')`
     * ({@see BillingCalendar}).
     */
    public function advanceByCadence(\DateTimeImmutable $from): \DateTimeImmutable
    {
        // Contracts anchored before billingAnchorDay existed fall back to their
        // live anchor's day (not $from's) so walking several cycles ahead from
        // a clamped date — e.g. the payment-overview projection — keeps the day.
        $anchorDay = $this->billingAnchorDay ?? ($this->nextBillingDate ?? $from)->format('j');

        return BillingCalendar::addMonths($from, $this->getBillingCadenceMonths(), (int) $anchorDay);
    }

    /**
     * End of the first rental period: one cadence step from the contract START
     * — the walk {@see PriceCalculator::buildPaymentSchedule()} quoted.
     */
    public function firstCycleEnd(): \DateTimeImmutable
    {
        $start = $this->startDate->setTime(0, 0);

        return BillingCalendar::addMonths($start, $this->getBillingCadenceMonths(), (int) $start->format('j'));
    }

    /**
     * The first payment arrived at $firstPaidAt and paid the first period
     * [start, start + cadence) — whether it came before the start (bank
     * transfer a week ahead, an order placed months ahead) or a few days after
     * it (admin onboarding). Later periods are due on the first payment's day
     * of month, as close before (or, for a late payer, after) each period as
     * that day falls.
     *
     * Callers handle the "a whole cycle late" case themselves (firstCycleEnd()
     * already passed): there is no future period to anchor then.
     *
     * $recordAsBilled: false for the card order's first payment — it never
     * counted as a "billed" cycle (lastBilledAt drives the ≥ 6-month advance
     * notice and the 5-minute double-charge guard of the card cron).
     *
     * @return BillingCycle the first period, now paid
     */
    public function startBilling(\DateTimeImmutable $firstPaidAt, bool $recordAsBilled = true): BillingCycle
    {
        $start = $this->startDate->setTime(0, 0);
        $cycle = $this->seedPeriodWalk($start, (int) $start->format('j'), $firstPaidAt);
        if ($recordAsBilled) {
            $this->markCyclePaid($firstPaidAt);
        }

        return $cycle;
    }

    /**
     * Record that the period the contract is currently due for has been paid
     * (card charge, bank transfer, GoPay payment link) and move both walks one
     * cadence step. The single place billing advances after a payment.
     *
     * $settledPeriodEnd: the open payment request covered a SHORTER period
     * than the contract would now compute (a prolonged prorated cycle settled
     * at its requested amount, 2026-11-03) — billing resumes exactly there, as
     * a single walk.
     *
     * @return BillingCycle the period this payment paid
     */
    public function recordCyclePaid(\DateTimeImmutable $paidAt, ?\DateTimeImmutable $settledPeriodEnd = null): BillingCycle
    {
        $periodStart = $this->nextPeriodStart() ?? $paidAt->setTime(0, 0);
        $end = $this->getEffectiveEndDate()->setTime(0, 0);
        $periodEnd = $this->advancePeriod($periodStart);

        if (null !== $settledPeriodEnd && $settledPeriodEnd->format('Y-m-d') < $periodEnd->format('Y-m-d')) {
            $periodEnd = $settledPeriodEnd->setTime(0, 0);
            if ($periodEnd >= $end) {
                $this->finishBilling($end);
            } else {
                $this->clearPeriodWalk();
                $this->assignNextBillingDate($periodEnd);
                $this->paidThroughDate = $periodEnd;
            }
        } elseif ($periodEnd >= $end) {
            $periodEnd = $end;
            $this->finishBilling($end);
        } elseif (null === $this->nextPeriodStart) {
            // Single walk: the next period starts on its due date.
            $this->assignNextBillingDate($periodEnd);
            $this->paidThroughDate = $periodEnd;
        } else {
            $this->nextPeriodStart = $periodEnd;
            $this->nextBillingDate = $this->dueDateForPeriod($periodEnd);
            $this->paidThroughDate = $periodEnd;
        }

        $this->markCyclePaid($paidAt);

        return new BillingCycle($periodStart, $periodEnd);
    }

    /**
     * First day of the rental period the next payment pays — what the amount
     * is computed from. Equals the due date in single-walk mode.
     */
    public function nextPeriodStart(): ?\DateTimeImmutable
    {
        // Single walk keeps the anchor's time of day, exactly like the
        // pre-two-walk code (cron timing is unchanged for legacy contracts).
        return $this->nextPeriodStart ?? $this->nextBillingDate;
    }

    /**
     * The rental period the next payment pays, end capped at the effective end.
     */
    public function currentBillingPeriod(): ?BillingCycle
    {
        $start = $this->nextPeriodStart();
        if (null === $start) {
            return null;
        }

        $end = $this->getEffectiveEndDate()->setTime(0, 0);
        $periodEnd = $this->advancePeriod($start);

        return new BillingCycle($start, $periodEnd < $end ? $periodEnd : $end);
    }

    /**
     * One cadence step along the PERIOD walk (contract start day). In
     * single-walk mode identical to {@see self::advanceByCadence()}.
     */
    public function advancePeriod(\DateTimeImmutable $periodStart): \DateTimeImmutable
    {
        if (null === $this->periodAnchorDay) {
            return $this->advanceByCadence($periodStart);
        }

        return BillingCalendar::addMonths($periodStart->setTime(0, 0), $this->getBillingCadenceMonths(), $this->periodAnchorDay);
    }

    /**
     * Due date of the period starting on $periodStart: the first payment's day
     * of month (clamped to short months) in the month chargeMonthOffset away —
     * never on or after the effective end (a late payer's last, short period
     * is then due on its own first day).
     */
    public function dueDateForPeriod(\DateTimeImmutable $periodStart): \DateTimeImmutable
    {
        $periodStart = $periodStart->setTime(0, 0);
        if (null === $this->chargeMonthOffset || null === $this->billingAnchorDay) {
            return $periodStart;
        }

        $dueMonth = BillingCalendar::addMonths($periodStart->setDate((int) $periodStart->format('Y'), (int) $periodStart->format('n'), 1), -$this->chargeMonthOffset, 1);
        $due = $dueMonth->setDate(
            (int) $dueMonth->format('Y'),
            (int) $dueMonth->format('n'),
            min($this->billingAnchorDay, (int) $dueMonth->format('t')),
        );

        return $due >= $this->getEffectiveEndDate()->setTime(0, 0) ? $periodStart : $due;
    }

    /**
     * $paidAt paid the period starting on $paidPeriodStart (period walk day
     * $periodAnchorDay). Seed both walks for the following period.
     */
    private function seedPeriodWalk(\DateTimeImmutable $paidPeriodStart, int $periodAnchorDay, \DateTimeImmutable $paidAt): BillingCycle
    {
        $paidPeriodStart = $paidPeriodStart->setTime(0, 0);
        // The payment's calendar day as the customer sees it (Prague), not the
        // server's UTC day — a payment at 00:30 Prague time is "the 15th".
        $paidDay = new \DateTimeImmutable(
            $paidAt->setTimezone(new \DateTimeZone('Europe/Prague'))->format('Y-m-d'),
            $paidPeriodStart->getTimezone(),
        );
        $chargeDay = (int) $paidDay->format('j');
        $end = $this->getEffectiveEndDate()->setTime(0, 0);

        $this->periodAnchorDay = $periodAnchorDay;
        $nextPeriodStart = $this->advancePeriod($paidPeriodStart);

        if ($nextPeriodStart >= $end) {
            $this->finishBilling($end);

            return new BillingCycle($paidPeriodStart, $end);
        }

        // Paid on/before its period: due in the period's month when the
        // payment day comes no later than the period day, else the month
        // before. Paid after it: same month when the payment day is no earlier
        // than the period day, else the month after.
        $chargeMonthOffset = $paidDay <= $paidPeriodStart
            ? ($chargeDay <= $periodAnchorDay ? 0 : 1)
            : ($chargeDay >= $periodAnchorDay ? 0 : -1);

        $this->nextPeriodStart = $nextPeriodStart;
        $this->billingAnchorDay = $chargeDay;
        $this->chargeMonthOffset = $chargeMonthOffset;
        $due = $this->dueDateForPeriod($nextPeriodStart);

        if ($due <= $paidDay) {
            // Safety net: never schedule a charge on or before the payment that
            // just happened (payment far behind its period). Fall back to the
            // single walk from the next period (anchored on the period day).
            $this->clearPeriodWalk();
            $this->billingAnchorDay = $periodAnchorDay;
            $this->assignNextBillingDate($nextPeriodStart);
        } else {
            $this->nextBillingDate = $due;
        }
        $this->paidThroughDate = $nextPeriodStart;

        return new BillingCycle($paidPeriodStart, $nextPeriodStart);
    }

    private function finishBilling(\DateTimeImmutable $end): void
    {
        $this->clearPeriodWalk();
        $this->nextBillingDate = null;
        $this->paidThroughDate = $end;
    }

    private function clearPeriodWalk(): void
    {
        $this->nextPeriodStart = null;
        $this->periodAnchorDay = null;
        $this->chargeMonthOffset = null;
    }

    private function markCyclePaid(\DateTimeImmutable $paidAt): void
    {
        $this->lastBilledAt = $paidAt;
        $this->failedBillingAttempts = 0;
        $this->lastBillingFailedAt = null;
        $this->pendingRecurringPaymentId = null;
        $this->paymentDemandSentAt = null;
        $this->paymentGraceUntil = null;
    }

    /**
     * Nothing left to bill: the rental is paid to the end of its (effective)
     * term. Holds for both paid-through conventions — customer-paid cycles
     * store the exclusive end, admin prepayments are canonicalised to it.
     */
    public function isPaidToEndOfTerm(): bool
    {
        return null === $this->nextBillingDate
            && null !== $this->paidThroughDate
            && $this->paidThroughDate->format('Y-m-d') >= $this->getEffectiveEndDate()->format('Y-m-d');
    }

    /**
     * The last day the customer has paid for — the ONLY value customer-facing
     * "Zaplaceno do …" wording may print. Never print paidThroughDate raw: it
     * is the first UNPAID day for customer-paid cycles but the last paid day
     * for admin prepayments, which produced "uhrazeno do X. Od X …".
     *
     * The day before the next unpaid PERIOD starts — means the same in every
     * mode and convention (with two-walk billing the due date is earlier).
     * Null when it can't be known: no payment yet, a one-shot rental, or a
     * wiped anchor.
     */
    public function lastPaidDay(): ?\DateTimeImmutable
    {
        $nextPeriodStart = $this->nextPeriodStart();
        if (null !== $nextPeriodStart) {
            return $nextPeriodStart->setTime(0, 0)->modify('-1 day');
        }

        if ($this->isPaidToEndOfTerm()) {
            return $this->getEffectiveEndDate()->setTime(0, 0)->modify('-1 day');
        }

        return null;
    }

    /**
     * The date to print after a customer-facing "Zaplaceno do …": the contract
     * end when nothing is left to pay (so it reads the same as the contract's
     * own "od … do …" and the "Smlouva trvá do" row next to it), otherwise the
     * last paid day — the day before the next payment is due.
     */
    public function displayPaidUntil(): ?\DateTimeImmutable
    {
        return $this->isPaidToEndOfTerm() ? $this->getEffectiveEndDate() : $this->lastPaidDay();
    }

    public function getBillingCadenceMonths(): int
    {
        return '+1 year' === $this->getBillingCadenceStep() ? 12 : 1;
    }

    /**
     * Every write of a non-null nextBillingDate goes through here so the
     * anchor day stays right without callers having to declare intent:
     *  - a month-end clamp of the current anchor (30.09 for an anchor on the
     *    31st) keeps the anchor, so the following cycle returns to the 31st;
     *  - any other date is a (re-)anchoring and becomes the new anchor day —
     *    e.g. a prolonged prorated cycle resuming on the 25th.
     */
    private function assignNextBillingDate(?\DateTimeImmutable $nextBillingDate): void
    {
        // Every caller writes a SINGLE-walk anchor (period starts on its due
        // date) — drop any two-walk state so the two never disagree.
        $this->clearPeriodWalk();
        $this->nextBillingDate = $nextBillingDate;

        if (null === $nextBillingDate) {
            return;
        }

        if (null !== $this->billingAnchorDay && BillingCalendar::isClampOf($nextBillingDate, $this->billingAnchorDay)) {
            return;
        }

        $this->billingAnchorDay = (int) $nextBillingDate->format('j');
    }

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

    /**
     * Unpaid usage between paidThroughDate and $terminationDate, prorated at
     * the monthly rate over a 30-day month. 0 when paid through that date.
     */
    public function calculateOutstandingDebt(\DateTimeImmutable $terminationDate): int
    {
        if (null === $this->paidThroughDate) {
            return 0;
        }

        if ($this->paidThroughDate >= $terminationDate) {
            return 0; // Paid through or past the termination date
        }

        $unpaidDays = (int) $this->paidThroughDate->diff($terminationDate)->days;

        if ($unpaidDays <= 0) {
            return 0;
        }

        // Spec 078 tranches: upfront contracts owe at the LOCKED order rate
        // (firstPaymentPrice / 12), not the live storage price — the debt must
        // match the tranche the customer failed to pay.
        $monthlyRate = BillingMode::ONE_TIME === $this->billingMode && $this->order->isPaidInUpfrontTranches()
            ? $this->order->getUpfrontLockedMonthlyRate()
            : $this->getEffectiveMonthlyAmount();
        $dailyRate = $monthlyRate / 30;

        return (int) round($unpaidDays * $dailyRate);
    }

    /**
     * Can the tenant set up a (new) card on the spec-077 card-setup page?
     * Recurring track without a live token, not free/yearly/terminated, and
     * the contract has not ended yet.
     */
    public function isEligibleForCardSetup(\DateTimeImmutable $now): bool
    {
        return $this->billingMode->isRecurring()
            && null === $this->goPayParentPaymentId
            && !$this->isFree()
            && !$this->isYearly()
            && !$this->isTerminated()
            // Nothing scheduled (paid to the end) → nothing for the setup
            // charge to pay; it would bill a period from today and move
            // paidThroughDate backwards.
            && null !== $this->nextBillingDate
            && $this->endDate >= $now->setTime(0, 0);
    }

    /**
     * The contract runs past what was paid, yet nothing is scheduled to bill
     * it: no billing anchor, so no charge, payment request, overdue sweep or
     * expiry-time debt will ever pick it up. Legitimate states never look like
     * this (a fully-paid contract has paidThroughDate == endDate); it only
     * arises from a wiped anchor — must be flagged to admins, not shown as
     * "zaplaceno". Mirrors the unscheduled branch of the overdue predicate.
     */
    public function hasUnscheduledUnpaidPeriod(): bool
    {
        return !$this->isTerminated()
            && !$this->isFree()
            && null === $this->nextBillingDate
            && null !== $this->paidThroughDate
            && $this->paidThroughDate->format('Y-m-d') < $this->getEffectiveEndDate()->format('Y-m-d');
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
        $this->applyInclusivePaidThrough($paidThroughDate);
    }

    /**
     * Admin-entered "paid through X" (external prepayment, off-system payment)
     * is the INCLUSIVE last paid day; billing resumes the day after.
     *
     * Paid to the end of the term — X is the day before the end or later (the
     * admin form steers "do konce smlouvy" to the end date itself) — becomes the
     * canonical fully-paid state shared with customer-paid cycles: no anchor
     * (nothing to bill, request or chase) and paidThroughDate >= end. Leaving
     * an anchor ON the end date instead would make the manual cron request a
     * one-day cycle and block the contract's expiry.
     */
    private function applyInclusivePaidThrough(\DateTimeImmutable $lastPaidDay): void
    {
        $end = $this->getEffectiveEndDate()->format('Y-m-d');
        $resumesOn = $lastPaidDay->modify('+1 day');

        if ($resumesOn->format('Y-m-d') >= $end) {
            $this->paidThroughDate = $lastPaidDay->format('Y-m-d') >= $end ? $lastPaidDay : $this->getEffectiveEndDate();
            $this->assignNextBillingDate(null);

            return;
        }

        $this->paidThroughDate = $lastPaidDay;
        $this->assignNextBillingDate($resumesOn);
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
     * The date the VOP čl. XI termination countdown runs from. Like
     * {@see self::effectiveDunningAnchor()}, but a customer whose due date
     * precedes the period it pays (paid N days early, two-walk billing) is
     * never terminated while still inside a period they already paid for:
     * the countdown starts when the unpaid period does (owner decision
     * 2026-11-25). Dunning e-mails still run from the due date.
     */
    public function terminationCountdownAnchor(): ?\DateTimeImmutable
    {
        $anchor = $this->effectiveDunningAnchor();
        $periodStart = null !== $this->nextBillingDate ? $this->nextPeriodStart() : null;
        if (null === $anchor || null === $periodStart) {
            return $anchor;
        }

        // An admin extension (spec 086) can only push the countdown further,
        // never below the end of the already-paid period.
        return $periodStart->setTime(0, 0) > $anchor->setTime(0, 0) ? $periodStart->setTime(0, 0) : $anchor;
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
        $this->applyInclusivePaidThrough($paidThroughDate);
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
