<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\BillingMode;
use App\Enum\ExpectedDuration;
use App\Enum\OrderStatus;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Enum\RentalType;
use App\Enum\SigningMethod;
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
     * Per-order monthly override carried from admin onboarding into Contract
     * creation. See spec 025 — copied onto Contract.individualMonthlyAmount in
     * OrderService::completeOrder so future recurring charges respect it.
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

    /**
     * Customer's self-reported expected stay length, asked only when they pick
     * UNLIMITED. Research signal for admins/landlords — never read by billing,
     * pricing, or any business rule. NULL for LIMITED orders and for legacy
     * UNLIMITED orders placed before this column existed.
     */
    #[ORM\Column(length: 10, nullable: true, enumType: ExpectedDuration::class)]
    public private(set) ?ExpectedDuration $expectedDuration = null;

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
        #[ORM\Column(length: 20, enumType: RentalType::class)]
        private(set) RentalType $rentalType,
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

    public function isUnlimited(): bool
    {
        return RentalType::UNLIMITED === $this->rentalType;
    }

    /**
     * Whether this order is billed on a monthly recurring cadence.
     *
     * Mirrors {@see PriceCalculator::needsRecurringBilling()}.
     * Three pricing modes total: isOneTime() | isFixedTermRecurring() | isUnlimited().
     *
     * The 28-day threshold is duplicated rather than imported from
     * PriceCalculator so the entity stays free of service deps. A unit test
     * pins the two values together.
     */
    public function isRecurring(): bool
    {
        if (null === $this->endDate) {
            return true;
        }

        return (int) $this->startDate->diff($this->endDate)->days >= PriceCalculator::WEEKLY_THRESHOLD_DAYS;
    }

    public function isFixedTermRecurring(): bool
    {
        return $this->isRecurring() && null !== $this->endDate;
    }

    public function isOneTime(): bool
    {
        return !$this->isRecurring();
    }

    public function getFirstPaymentPriceInCzk(): float
    {
        return $this->firstPaymentPrice / 100;
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

    public function setExpectedDuration(?ExpectedDuration $duration): void
    {
        $this->expectedDuration = $duration;
    }

    public function setUploadedContractDocumentPath(string $path): void
    {
        $this->uploadedContractDocumentPath = $path;
    }

    public function hasUploadedContract(): bool
    {
        return null !== $this->uploadedContractDocumentPath;
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
        $this->debtPaidAt = $now;
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
