<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class Payment
{
    #[ORM\ManyToOne(targetEntity: SelfBillingInvoice::class)]
    #[ORM\JoinColumn(nullable: true)]
    public private(set) ?SelfBillingInvoice $selfBillingInvoice = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Order::class)]
        #[ORM\JoinColumn(nullable: true)]
        private(set) ?Order $order,
        #[ORM\ManyToOne(targetEntity: Contract::class)]
        #[ORM\JoinColumn(nullable: true)]
        private(set) ?Contract $contract,
        #[ORM\ManyToOne(targetEntity: Storage::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Storage $storage,
        #[ORM\Column]
        private(set) int $amount,
        #[ORM\Column]
        private(set) \DateTimeImmutable $paidAt,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
    }

    public function linkToSelfBillingInvoice(SelfBillingInvoice $invoice): void
    {
        $this->selfBillingInvoice = $invoice;
    }

    public function isBilled(): bool
    {
        return null !== $this->selfBillingInvoice;
    }

    public function getAmountInCzk(): float
    {
        return $this->amount / 100;
    }
}
