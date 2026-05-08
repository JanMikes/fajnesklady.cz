<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'contract_price_change')]
#[ORM\Index(columns: ['contract_id', 'changed_at'], name: 'idx_contract_price_change_contract_changed')]
class ContractPriceChange
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Contract::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) Contract $contract,
        #[ORM\Column(nullable: true)]
        private(set) ?int $previousAmount,
        #[ORM\Column(nullable: true)]
        private(set) ?int $newAmount,
        #[ORM\Column]
        private(set) \DateTimeImmutable $changedAt,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private(set) ?User $changedBy,
        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private(set) ?string $reason,
    ) {
    }
}
