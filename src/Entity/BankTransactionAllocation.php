<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AllocationStepType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * One part of one bank transfer, applied to one obligation (spec 091).
 *
 * A single transfer can feed several obligations — settle an onboarding debt,
 * then part of a rental cycle — so a transfer has one row per waterfall step it
 * touched.
 *
 * These rows are the record of *what money was for*. Before them, "how much has
 * this order received" was a single undifferentiated sum over bank transactions
 * (`BankTransactionRepository::sumReceivedByOrder()`), which was read both as
 * "how much of the debt is paid" and as "how much of the first payment is paid".
 * Money settling one obligation therefore silently discounted the other, and an
 * order could complete under-collected. Typed allocations keep the pools
 * disjoint by construction.
 */
#[ORM\Entity]
#[ORM\Index(fields: ['order', 'type'])]
class BankTransactionAllocation
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: BankTransaction::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) BankTransaction $bankTransaction,
        #[ORM\ManyToOne(targetEntity: Order::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Order $order,
        #[ORM\Column(length: 30, enumType: AllocationStepType::class)]
        private(set) AllocationStepType $type,
        #[ORM\Column]
        private(set) int $amountInHaler,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private(set) \DateTimeImmutable $createdAt,
        #[ORM\ManyToOne(targetEntity: Contract::class)]
        #[ORM\JoinColumn(nullable: true)]
        private(set) ?Contract $contract = null,
    ) {
    }
}
