<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One row per (Contract, billing cycle) of a MANUAL_RECURRING contract.
 * Tracks which reminder stages have already been emitted so re-runs of the
 * cron never double-send. The unique constraint on (contract_id, period_start)
 * is the schema-level backstop against parallel creation.
 */
#[ORM\Entity]
#[ORM\Table(name: 'manual_payment_request')]
#[ORM\UniqueConstraint(name: 'uniq_manual_payment_request_contract_period', columns: ['contract_id', 'period_start'])]
class ManualPaymentRequest
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_PAID = 'paid';
    public const string STATUS_CANCELLED = 'cancelled';
    public const string STATUS_EXPIRED = 'expired';

    #[ORM\Column(length: 20)]
    public private(set) string $status;

    #[ORM\Column(nullable: true)]
    public private(set) ?string $goPayPaymentId = null;

    #[ORM\Column(length: 1000, nullable: true)]
    public private(set) ?string $goPayGatewayUrl = null;

    /**
     * Keys: 'initial', 'd_minus_2', 'd_zero', 'd_plus_3', 'd_plus_7' (matching
     * {@see \App\Service\Billing\ManualBillingReminderSchedule}::STAGE_*).
     * Values: ISO-8601 timestamp of when the stage e-mail was dispatched.
     *
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '{}'])]
    public private(set) array $sentStages = [];

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $paidAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Contract::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Contract $contract,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $periodStart,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $periodEnd,
        #[ORM\Column]
        private(set) int $amount,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->status = self::STATUS_PENDING;
    }

    public function attachGoPayPayment(string $paymentId, string $gatewayUrl): void
    {
        $this->goPayPaymentId = $paymentId;
        $this->goPayGatewayUrl = $gatewayUrl;
    }

    /**
     * @throws \InvalidArgumentException when $stage is not a known reminder stage
     */
    public function recordStageSent(string $stage, \DateTimeImmutable $now): void
    {
        if (!in_array($stage, [
            'initial',
            'd_minus_2',
            'd_zero',
            'd_plus_3',
            'd_plus_7',
        ], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown manual-billing reminder stage "%s".', $stage));
        }

        $stages = $this->sentStages;
        $stages[$stage] = $now->format(\DateTimeInterface::ATOM);
        $this->sentStages = $stages;
    }

    public function hasStageSent(string $stage): bool
    {
        return array_key_exists($stage, $this->sentStages);
    }

    public function markPaid(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_PAID;
        $this->paidAt = $now;
    }

    /**
     * Re-open this cycle's request after an admin extends the deadline
     * (spec 086) so the post-grace reminder ladder re-fires on the same
     * period row instead of being silenced by the sentStages gate.
     */
    public function reopenForExtension(): void
    {
        $this->sentStages = [];
        $this->status = self::STATUS_PENDING;
        $this->paidAt = null;
    }

    public function markExpired(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_EXPIRED;
    }

    /**
     * Void this cycle's request when the contract is terminated — its
     * receivable is superseded by the terminated-contract debt (or forgiven),
     * so the payment overview must stop rendering it as an overdue row.
     */
    public function cancel(): void
    {
        $this->status = self::STATUS_CANCELLED;
    }

    public function isPaid(): bool
    {
        return self::STATUS_PAID === $this->status;
    }
}
