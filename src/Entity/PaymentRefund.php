<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\PaymentRefundStatus;
use App\Enum\RefundMode;
use App\Event\PaymentRefunded;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One refund of a card (GoPay) payment — the OPERATION: what was asked for,
 * why, by whom, and what GoPay answered. The money itself is booked in the
 * ledger as a negative {@see Payment} (refundOf → the original) once GoPay
 * confirms, so every revenue SUM nets refunds without knowing about them.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_payment_refund_status', columns: ['status'])]
// One live admin refund per payment — a double submit can never refund twice.
// External (GoPay console) refunds are exempt: GoPay allows several.
#[ORM\UniqueConstraint(
    name: 'uniq_payment_refund_active_per_payment',
    columns: ['payment_id'],
    // Written exactly as PostgreSQL normalises it, or schema diffs never settle.
    options: ['where' => "(((status)::text <> 'failed'::text) AND ((mode)::text <> 'external'::text))"],
)]
class PaymentRefund implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Column(length: 20, enumType: PaymentRefundStatus::class)]
    public private(set) PaymentRefundStatus $status;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $failureReason = null;

    // The Fakturoid correction document issued for this refund (an Invoice
    // row with correctionOf → the original invoice).
    #[ORM\ManyToOne(targetEntity: Invoice::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?Invoice $creditNote = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Payment::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Payment $payment,
        #[ORM\ManyToOne(targetEntity: Order::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Order $order,
        #[ORM\ManyToOne(targetEntity: Contract::class)]
        #[ORM\JoinColumn(nullable: true)]
        private(set) ?Contract $contract,
        #[ORM\Column]
        private(set) int $amount,
        #[ORM\Column(length: 20, enumType: RefundMode::class)]
        private(set) RefundMode $mode,
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private(set) ?\DateTimeImmutable $refundFrom,
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private(set) ?\DateTimeImmutable $periodStart,
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private(set) ?\DateTimeImmutable $periodEnd,
        #[ORM\Column]
        private(set) bool $issueCreditNote,
        #[ORM\Column(length: 500, nullable: true)]
        private(set) ?string $reason,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true)]
        private(set) ?User $requestedBy,
        #[ORM\Column]
        private(set) \DateTimeImmutable $requestedAt,
    ) {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Refund amount must be positive.');
        }
        if ($amount > $payment->amount) {
            throw new \DomainException('Refund cannot exceed the refunded payment.');
        }
        if ($payment->isRefund()) {
            throw new \DomainException('A refund row cannot itself be refunded.');
        }

        $this->status = PaymentRefundStatus::PENDING;
    }

    public function markSucceeded(\DateTimeImmutable $now): void
    {
        if (PaymentRefundStatus::PENDING !== $this->status) {
            throw new \DomainException(sprintf('Refund is already %s.', $this->status->value));
        }

        $this->status = PaymentRefundStatus::SUCCEEDED;
        $this->resolvedAt = $now;

        $this->recordThat(new PaymentRefunded(
            refundId: $this->id,
            orderId: $this->order->id,
            occurredOn: $now,
        ));
    }

    public function markFailed(\DateTimeImmutable $now, string $reason): void
    {
        if (PaymentRefundStatus::PENDING !== $this->status) {
            throw new \DomainException(sprintf('Refund is already %s.', $this->status->value));
        }

        $this->status = PaymentRefundStatus::FAILED;
        $this->resolvedAt = $now;
        $this->failureReason = mb_substr($reason, 0, 500);
    }

    public function attachCreditNote(Invoice $creditNote): void
    {
        $this->creditNote = $creditNote;
    }

    public function isPending(): bool
    {
        return PaymentRefundStatus::PENDING === $this->status;
    }

    public function isSucceeded(): bool
    {
        return PaymentRefundStatus::SUCCEEDED === $this->status;
    }

    public function isExternal(): bool
    {
        return RefundMode::EXTERNAL === $this->mode;
    }

    public function getAmountInCzk(): float
    {
        return $this->amount / 100;
    }
}
