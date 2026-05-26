<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\FineType;
use App\Event\FineIssued;
use App\Event\FinePaid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class Fine implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Column(length: 10, unique: true, nullable: true)]
    private(set) ?string $variableSymbol = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?string $goPayPaymentId = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?string $goPayGatewayUrl = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?User $cancelledBy = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $reminder1SentAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $reminder2SentAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Contract::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Contract $contract,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) User $user,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) User $issuedBy,
        #[ORM\Column(type: Types::STRING, enumType: FineType::class)]
        private(set) FineType $type,
        #[ORM\Column]
        private(set) int $amountInHaler,
        #[ORM\Column(type: Types::TEXT)]
        private(set) string $description,
        #[ORM\Column]
        private(set) \DateTimeImmutable $issuedAt,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->recordThat(new FineIssued(
            fineId: $this->id,
            contractId: $this->contract->id,
            userId: $this->user->id,
            type: $this->type,
            amountInHaler: $this->amountInHaler,
            occurredOn: $this->issuedAt,
        ));
    }

    public function getAmountInCzk(): float
    {
        return $this->amountInHaler / 100;
    }

    public function isPaid(): bool
    {
        return null !== $this->paidAt;
    }

    public function isCancelled(): bool
    {
        return null !== $this->cancelledAt;
    }

    public function isPayable(): bool
    {
        return !$this->isPaid() && !$this->isCancelled();
    }

    public function markPaid(\DateTimeImmutable $now): void
    {
        $this->paidAt = $now;

        $this->recordThat(new FinePaid(
            fineId: $this->id,
            contractId: $this->contract->id,
            userId: $this->user->id,
            amountInHaler: $this->amountInHaler,
            occurredOn: $now,
        ));
    }

    public function cancel(User $cancelledBy, \DateTimeImmutable $now): void
    {
        $this->cancelledAt = $now;
        $this->cancelledBy = $cancelledBy;
    }

    public function assignVariableSymbol(string $vs): void
    {
        $this->variableSymbol = $vs;
    }

    public function setGoPayPayment(string $paymentId, string $gatewayUrl): void
    {
        $this->goPayPaymentId = $paymentId;
        $this->goPayGatewayUrl = $gatewayUrl;
    }

    public function markReminder1Sent(\DateTimeImmutable $now): void
    {
        $this->reminder1SentAt = $now;
    }

    public function markReminder2Sent(\DateTimeImmutable $now): void
    {
        $this->reminder2SentAt = $now;
    }
}
