<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(fields: ['fioTransactionId'])]
class BankTransaction
{
    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?Order $pairedOrder = null;

    #[ORM\ManyToOne(targetEntity: Contract::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?Contract $pairedContract = null;

    #[ORM\Column(length: 20, options: ['default' => 'unmatched'])]
    public private(set) string $status = 'unmatched';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?User $pairedBy = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $pairedAt = null;

    #[ORM\Column(length: 30, nullable: true)]
    public private(set) ?string $matchMethod = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $expectedAmountInHaler = null;

    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $ignoreReason = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\Column(length: 50, unique: true)]
        private(set) string $fioTransactionId,
        #[ORM\Column]
        private(set) int $amount,
        #[ORM\Column(length: 3)]
        private(set) string $currency,
        #[ORM\Column(length: 10, nullable: true)]
        private(set) ?string $variableSymbol,
        #[ORM\Column(length: 50, nullable: true)]
        private(set) ?string $senderAccountNumber,
        #[ORM\Column(length: 255, nullable: true)]
        private(set) ?string $senderName,
        #[ORM\Column]
        private(set) \DateTimeImmutable $transactionDate,
        #[ORM\Column(length: 500, nullable: true)]
        private(set) ?string $comment,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
    }

    public function pairToOrder(Order $order, string $matchMethod, ?User $pairedBy, \DateTimeImmutable $now): void
    {
        $this->pairedOrder = $order;
        $this->matchMethod = $matchMethod;
        $this->pairedBy = $pairedBy;
        $this->pairedAt = $now;
        $this->status = 'matched';
    }

    public function pairToContract(Contract $contract, string $matchMethod, ?User $pairedBy, \DateTimeImmutable $now): void
    {
        $this->pairedContract = $contract;
        $this->pairedOrder = $contract->order;
        $this->matchMethod = $matchMethod;
        $this->pairedBy = $pairedBy;
        $this->pairedAt = $now;
        $this->status = 'matched';
    }

    public function markAmountMismatch(Order $order, string $matchMethod, int $expectedAmount, \DateTimeImmutable $now): void
    {
        $this->pairedOrder = $order;
        $this->matchMethod = $matchMethod;
        $this->expectedAmountInHaler = $expectedAmount;
        $this->pairedAt = $now;
        $this->status = 'amount_mismatch';
    }

    public function markAmountMismatchContract(Contract $contract, string $matchMethod, int $expectedAmount, \DateTimeImmutable $now): void
    {
        $this->pairedContract = $contract;
        $this->pairedOrder = $contract->order;
        $this->matchMethod = $matchMethod;
        $this->expectedAmountInHaler = $expectedAmount;
        $this->pairedAt = $now;
        $this->status = 'amount_mismatch';
    }

    public function promoteToMatched(\DateTimeImmutable $now): void
    {
        if ('amount_mismatch' !== $this->status) {
            throw new \DomainException(sprintf('Cannot promote transaction %s: status is "%s", expected "amount_mismatch".', $this->id->toRfc4122(), $this->status));
        }

        $this->status = 'matched';
        $this->pairedAt = $now;
    }

    public function markIgnored(User $admin, ?string $reason, \DateTimeImmutable $now): void
    {
        $this->pairedBy = $admin;
        $this->pairedAt = $now;
        $this->ignoreReason = $reason;
        $this->status = 'ignored';
    }

    public function unignore(): void
    {
        $this->status = 'unmatched';
        $this->ignoreReason = null;
        $this->pairedBy = null;
        $this->pairedAt = null;
    }

    public function isUnmatched(): bool
    {
        return 'unmatched' === $this->status;
    }

    public function isMatched(): bool
    {
        return 'matched' === $this->status;
    }

    public function isIgnored(): bool
    {
        return 'ignored' === $this->status;
    }

    public function isAmountMismatch(): bool
    {
        return 'amount_mismatch' === $this->status;
    }
}
