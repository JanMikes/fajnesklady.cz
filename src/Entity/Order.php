<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BillingMode;
use App\Enum\OrderStatus;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Enum\SigningMethod;
use App\Event\OnboardingDebtPaid;
use App\Event\OrderCancelled;
use App\Event\OrderCompleted;
use App\Event\OrderCreated;
use App\Event\OrderExpired;
use App\Event\OrderPaid;
use App\Event\OrderPlaced;
use App\Service\PriceCalculator;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
class Order implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Column(length: 30, enumType: OrderStatus::class)]
    public private(set) OrderStatus $status;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $termsAcceptedAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $earlyStartWaiverAcceptedAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?string $goPayPaymentId = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?string $goPayParentPaymentId = null;

    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $signaturePath = null;

    #[ORM\Column(length: 10, nullable: true, enumType: SigningMethod::class)]
    public private(set) ?SigningMethod $signingMethod = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $signatureTypedName = null;

    #[ORM\Column(length: 50, nullable: true)]
    public private(set) ?string $signatureStyleId = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $signedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    public private(set) ?string $signingPlace = null;

    /**
     * Captured at the moment the customer signed the smlouva / consented to
     * recurring payments. Stored verbatim so we can produce them as evidence
     * if a chargeback or GoPay audit asks who consented from where. Not used
     * by any business logic — write-once at sign-off, read-only afterwards.
     */
    #[ORM\Column(length: 45, nullable: true)]
    public private(set) ?string $signerIpAddress = null;

    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $signerUserAgent = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?bool $isAdminCreated = null;

    #[ORM\Column(length: 128, nullable: true, unique: true)]
    public private(set) ?string $signingToken = null;

    #[ORM\Column(length: 20, nullable: true, enumType: PaymentMethod::class)]
    public private(set) ?PaymentMethod $paymentMethod = null;

    #[ORM\Column(length: 10, nullable: true, unique: true)]
    public private(set) ?string $variableSymbol = null;

    /**
     * Decides the SUBSEQUENT billing cadence (orthogonal to paymentMethod which
     * decides the first payment). AUTO_RECURRING = GoPay ON_DEMAND token + cron
     * auto-charges; MANUAL_RECURRING = no token, cron emails one-time payment
     * links each cycle; ONE_TIME = single payment, no further cycles. Locked
     * at order creation; never mutated thereafter.
     */
    #[ORM\Column(length: 20, enumType: BillingMode::class, options: ['default' => 'auto_recurring'])]
    public private(set) BillingMode $billingMode = BillingMode::AUTO_RECURRING;

    /**
     * Snapshot of the Place's manual-billing schedule at the moment this order
     * was created. Read by SendManualBillingPaymentRequestsCommand so a running
     * rental keeps the cadence it was placed under even if the operator later
     * edits the Place's offsets.
     */
    #[ORM\Column(options: ['default' => -7])]
    public private(set) int $manualBillingOffsetInitial = -7;

    #[ORM\Column(options: ['default' => -2])]
    public private(set) int $manualBillingOffsetReminder = -2;

    #[ORM\Column(options: ['default' => 0])]
    public private(set) int $manualBillingOffsetFinalDue = 0;

    #[ORM\Column(options: ['default' => 3])]
    public private(set) int $manualBillingOffsetOverdueFirst = 3;

    #[ORM\Column(options: ['default' => 7])]
    public private(set) int $manualBillingOffsetOverdueFinal = 7;

    /**
     * Per-order price override carried from admin onboarding into Contract
     * creation. The figure follows the payment frequency: per month (MONTHLY),
     * per year (YEARLY), or the whole-rental total (single-payment ONE_TIME).
     * See spec 025 — copied onto Contract.individualMonthlyAmount in
     * OrderService::completeOrder so future recurring charges respect it
     * (except the upfront total, which stays order-only: it is fully collected
     * via firstPaymentPrice and must never become a per-cycle figure).
     *
     * null → standard storage rate; 0 → free; > 0 → individual price.
     */
    #[ORM\Column(nullable: true)]
    public private(set) ?int $individualMonthlyAmount = null;

    /**
     * Date through which the customer has prepaid externally (cash / bank
     * transfer). Carried from onboarding into Contract.paidThroughDate so the
     * recurring cron does not bill until afterwards.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public private(set) ?\DateTimeImmutable $paidThroughDate = null;

    /**
     * Admin who created this onboarding order. Provenance for the locked-in
     * monthly price; used as the actor on the first ContractPriceChange row.
     */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?User $createdByAdmin = null;

    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $uploadedContractDocumentPath = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $onboardingDebtInHaler = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $debtPaidAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?string $debtGoPayPaymentId = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) User $user,
        #[ORM\ManyToOne(targetEntity: Storage::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Storage $storage,
        #[ORM\Column(length: 20, enumType: PaymentFrequency::class, nullable: true)]
        private(set) ?PaymentFrequency $paymentFrequency,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $startDate,
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private(set) ?\DateTimeImmutable $endDate,
        #[ORM\Column(name: 'total_price')]
        private(set) int $firstPaymentPrice,
        #[ORM\Column]
        private(set) \DateTimeImmutable $expiresAt,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->status = OrderStatus::CREATED;

        $this->recordThat(new OrderCreated(
            orderId: $this->id,
            userId: $this->user->id,
            storageId: $this->storage->id,
            firstPaymentPrice: $this->firstPaymentPrice,
            occurredOn: $this->createdAt,
        ));
    }

    public function reserve(\DateTimeImmutable $now): void
    {
        $this->status = OrderStatus::RESERVED;
        $this->storage->reserve($now);

        $this->recordThat(new OrderPlaced(
            orderId: $this->id,
            occurredOn: $now,
        ));
    }

    public function acceptTerms(\DateTimeImmutable $now): void
    {
        $this->termsAcceptedAt = $now;
    }

    public function hasAcceptedTerms(): bool
    {
        return null !== $this->termsAcceptedAt;
    }

    public function acceptEarlyStartWaiver(\DateTimeImmutable $now): void
    {
        $this->earlyStartWaiverAcceptedAt = $now;
    }

    public function hasAcceptedEarlyStartWaiver(): bool
    {
        return null !== $this->earlyStartWaiverAcceptedAt;
    }

    public function markAwaitingPayment(\DateTimeImmutable $now): void
    {
        $this->status = OrderStatus::AWAITING_PAYMENT;
    }

    public function markPaid(\DateTimeImmutable $now, ?int $amountOverride = null): void
    {
        $this->status = OrderStatus::PAID;
        $this->paidAt = $now;

        $this->recordThat(new OrderPaid(
            orderId: $this->id,
            occurredOn: $now,
            amountOverride: $amountOverride,
        ));
    }

    public function complete(Uuid $contractId, \DateTimeImmutable $now): void
    {
        $this->status = OrderStatus::COMPLETED;
        $this->storage->occupy($now);

        $this->recordThat(new OrderCompleted(
            orderId: $this->id,
            contractId: $contractId,
            occurredOn: $now,
        ));
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        $this->status = OrderStatus::CANCELLED;
        $this->cancelledAt = $now;
        if ($this->storage->isReserved()) {
            $this->storage->release($now);
        }

        $this->recordThat(new OrderCancelled(
            orderId: $this->id,
            occurredOn: $now,
        ));
    }

    public function expire(\DateTimeImmutable $now): void
    {
        $this->status = OrderStatus::EXPIRED;
        if ($this->storage->isReserved()) {
            $this->storage->release($now);
        }

        $this->recordThat(new OrderExpired(
            orderId: $this->id,
            occurredOn: $now,
        ));
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt && !$this->status->isTerminal() && OrderStatus::PAID !== $this->status;
    }

    public function canBePaid(): bool
    {
        return in_array($this->status, [OrderStatus::CREATED, OrderStatus::RESERVED, OrderStatus::AWAITING_PAYMENT], true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [OrderStatus::CREATED, OrderStatus::RESERVED, OrderStatus::AWAITING_PAYMENT], true);
    }

    public function cancellationBlockedReason(): ?string
    {
        if ($this->canBeCancelled()) {
            return null;
        }

        return match ($this->status) {
            OrderStatus::PAID => 'Objednávku nelze zrušit, protože je již zaplacena.',
            OrderStatus::COMPLETED => 'Objednávku nelze zrušit, protože je již dokončena.',
            OrderStatus::CANCELLED => 'Objednávka je již zrušena.',
            OrderStatus::EXPIRED => 'Objednávka je již expirovaná.',
            default => 'Objednávku nelze zrušit.',
        };
    }

    public function canBeCompleted(): bool
    {
        return OrderStatus::PAID === $this->status;
    }

    /**
     * Legacy-only: orders placed before spec 076 as "doba neurčitá" carry a
     * NULL endDate. Every order created since always has a concrete endDate,
     * so this can only return true for those historical rows. Kept so status
     * pages, e-mails and schedules of legacy orders keep rendering correctly.
     */
    public function isOpenEnded(): bool
    {
        return null === $this->endDate;
    }

    /**
     * Whether this order is billed on a recurring cadence.
     *
     * Mirrors {@see PriceCalculator::needsRecurringBilling()} for MONTHLY /
     * YEARLY frequencies; a ONE_TIME frequency (whole rental prepaid upfront,
     * spec 078) is never recurring regardless of duration. Frequency is locked
     * at creation, so this is correct pre- and post-acceptance — unlike
     * billingMode, which stays at its default until the order is accepted.
     * Three pricing modes total: isOneTime() | isFixedTermRecurring() | isOpenEnded().
     *
     * The 31-day threshold comes from PriceCalculator; a unit test pins the
     * two predicates together for non-ONE_TIME orders.
     */
    public function isRecurring(): bool
    {
        if (PaymentFrequency::ONE_TIME === $this->paymentFrequency) {
            return false; // whole amount paid upfront (spec 078)
        }

        if (null === $this->endDate) {
            return true;
        }

        return (int) $this->startDate->diff($this->endDate)->days >= PriceCalculator::WEEKLY_THRESHOLD_DAYS;
    }

    /**
     * Whether firstPaymentPrice is a PER-YEAR figure. Display surfaces must
     * never label it "/ měsíc" for yearly orders — the recurring cadence (and
     * the meaning of the locked amount) follows the payment frequency.
     */
    public function isYearlyFrequency(): bool
    {
        return PaymentFrequency::YEARLY === $this->paymentFrequency;
    }

    public function isFixedTermRecurring(): bool
    {
        return $this->isRecurring() && null !== $this->endDate;
    }

    public function isOneTime(): bool
    {
        return !$this->isRecurring();
    }

    /**
     * Spec 078 tranches: an upfront (ONE_TIME frequency) rental longer than
     * 12 monthly billing periods is paid in yearly tranches — firstPaymentPrice
     * is then only the FIRST tranche (12 months), not the whole rental, and
     * display surfaces must not call it "Celková cena".
     *
     * Mirrors the split in PriceCalculator::buildUpfrontSchedule(): a 13th
     * monthly walk entry exists iff 12 iterative '+1 month' steps from
     * startDate still land strictly before endDate (iterative, because the
     * walk advances month by month and PHP month-end overflow makes that
     * differ from a single '+12 months' jump).
     */
    public function isPaidInUpfrontTranches(): bool
    {
        if (PaymentFrequency::ONE_TIME !== $this->paymentFrequency || null === $this->endDate) {
            return false;
        }

        $cursor = $this->startDate;
        for ($i = 0; $i < PriceCalculator::MONTHS_PER_UPFRONT_TRANCHE; ++$i) {
            $cursor = $cursor->modify('+1 month');
        }

        return $cursor < $this->endDate;
    }

    /**
     * Locked monthly rate of a > 12-month upfront order (spec 078 tranches).
     * The first tranche is always 12 FULL months — the prorated tail can only
     * sit in the last tranche — so firstPaymentPrice / 12 recovers the rate
     * the customer signed EXACTLY. Every tranche billed later must use this
     * locked rate, never the live storage price (which admins may edit during
     * the rental). Only meaningful when {@see self::isPaidInUpfrontTranches()}.
     */
    public function getUpfrontLockedMonthlyRate(): int
    {
        return intdiv($this->firstPaymentPrice, PriceCalculator::MONTHS_PER_UPFRONT_TRANCHE);
    }

    public function getFirstPaymentPriceInCzk(): float
    {
        return $this->firstPaymentPrice / 100;
    }

    /**
     * First day the customer owes rent after an external prepayment; null when
     * the order is not externally prepaid.
     */
    public function billingResumesOn(): ?\DateTimeImmutable
    {
        return $this->paidThroughDate?->modify('+1 day');
    }

    public function prepaidCoversWholeTerm(): bool
    {
        return null !== $this->paidThroughDate
            && null !== $this->endDate
            && $this->paidThroughDate >= $this->endDate;
    }

    public function attachSignature(
        string $signaturePath,
        SigningMethod $signingMethod,
        ?string $typedName,
        ?string $styleId,
        string $signingPlace,
        \DateTimeImmutable $now,
        ?string $signerIpAddress = null,
        ?string $signerUserAgent = null,
    ): void {
        $this->signaturePath = $signaturePath;
        $this->signingMethod = $signingMethod;
        $this->signatureTypedName = $typedName;
        $this->signatureStyleId = $styleId;
        $this->signingPlace = $signingPlace;
        $this->signerIpAddress = $signerIpAddress;
        $this->signerUserAgent = $signerUserAgent;
        $this->signedAt = $now;
    }

    public function hasSignature(): bool
    {
        return null !== $this->signaturePath && null !== $this->signedAt;
    }

    public function setGoPayPaymentId(string $paymentId): void
    {
        $this->goPayPaymentId = $paymentId;
    }

    /**
     * Forget a dead GoPay payment session (CANCELED/TIMEOUTED webhook). The
     * order itself stays payable until {@see self::$expiresAt} — the customer
     * gets a fresh GoPay payment on the next pay attempt. Clearing also makes
     * re-deliveries of the same webhook no-ops (the order is no longer found
     * by the dead payment ID).
     */
    public function clearGoPayPaymentId(): void
    {
        $this->goPayPaymentId = null;
    }

    public function setGoPayParentPaymentId(string $parentPaymentId): void
    {
        $this->goPayParentPaymentId = $parentPaymentId;
    }

    public function hasRecurringPaymentSetup(): bool
    {
        return null !== $this->goPayParentPaymentId;
    }

    public function markAsAdminCreated(): void
    {
        $this->isAdminCreated = true;
    }

    public function setSigningToken(string $token): void
    {
        $this->signingToken = $token;
    }

    public function clearSigningToken(): void
    {
        $this->signingToken = null;
    }

    public function setPaymentMethod(PaymentMethod $method): void
    {
        $this->paymentMethod = $method;
    }

    public function assignVariableSymbol(string $variableSymbol): void
    {
        $this->variableSymbol = $variableSymbol;
    }

    public function setBillingMode(BillingMode $mode): void
    {
        $this->billingMode = $mode;
    }

    /**
     * Snapshot the Place's manual-billing schedule onto this order. Called
     * once at creation by {@see \App\Service\OrderService::createOrder()}.
     */
    public function setManualBillingSchedule(
        int $initial,
        int $reminder,
        int $finalDue,
        int $overdueFirst,
        int $overdueFinal,
    ): void {
        $this->manualBillingOffsetInitial = $initial;
        $this->manualBillingOffsetReminder = $reminder;
        $this->manualBillingOffsetFinalDue = $finalDue;
        $this->manualBillingOffsetOverdueFirst = $overdueFirst;
        $this->manualBillingOffsetOverdueFinal = $overdueFinal;
    }

    public function extendExpiration(\DateTimeImmutable $newExpiresAt): void
    {
        $this->expiresAt = $newExpiresAt;
    }

    public function setUploadedContractDocumentPath(string $path): void
    {
        $this->uploadedContractDocumentPath = $path;
    }

    public function hasUploadedContract(): bool
    {
        return null !== $this->uploadedContractDocumentPath;
    }

    /**
     * Whether the admin-uploaded contract is an image (vs. a PDF). Drives the
     * signing page's preview embed: <img> for images, <object> for PDFs.
     */
    public function uploadedContractIsImage(): bool
    {
        if (null === $this->uploadedContractDocumentPath) {
            return false;
        }

        return in_array(
            strtolower(pathinfo($this->uploadedContractDocumentPath, PATHINFO_EXTENSION)),
            ['jpg', 'jpeg', 'png'],
            true,
        );
    }

    public function setOnboardingDebt(int $amountInHaler): void
    {
        $this->onboardingDebtInHaler = $amountInHaler;
    }

    public function hasUnpaidDebt(): bool
    {
        return null !== $this->onboardingDebtInHaler
            && $this->onboardingDebtInHaler > 0
            && null === $this->debtPaidAt;
    }

    public function hasDebt(): bool
    {
        return null !== $this->onboardingDebtInHaler && $this->onboardingDebtInHaler > 0;
    }

    public function markDebtPaid(\DateTimeImmutable $now): void
    {
        // Idempotent by construction: the GoPay webhook and the FIO cron can
        // both race to clear the same debt. Without this guard a second call
        // would re-record OnboardingDebtPaid (→ a duplicate Fakturoid debt
        // invoice + receipt email). Callers still pre-check hasUnpaidDebt(),
        // but the entity must not depend on caller discipline.
        if (null !== $this->debtPaidAt) {
            return;
        }

        $this->debtPaidAt = $now;

        $this->recordThat(new OnboardingDebtPaid(
            orderId: $this->id,
            userId: $this->user->id,
            amountInHaler: $this->onboardingDebtInHaler ?? 0, // non-null > 0 whenever a debt exists; ?? 0 satisfies PHPStan
            occurredOn: $now,
        ));
    }

    public function setDebtGoPayPaymentId(string $paymentId): void
    {
        $this->debtGoPayPaymentId = $paymentId;
    }

    public function getDebtAmountInCzk(): ?float
    {
        return null !== $this->onboardingDebtInHaler ? $this->onboardingDebtInHaler / 100 : null;
    }

    /**
     * Write-once at admin onboarding creation. Both fields propagate to the
     * Contract when {@see \App\Service\OrderService::completeOrder()} runs.
     */
    public function setOnboardingBillingTerms(
        ?int $individualMonthlyAmount,
        ?\DateTimeImmutable $paidThroughDate,
        ?User $createdByAdmin = null,
    ): void {
        $this->individualMonthlyAmount = $individualMonthlyAmount;
        $this->paidThroughDate = $paidThroughDate;
        if (null !== $createdByAdmin) {
            $this->createdByAdmin = $createdByAdmin;
        }
    }
}
