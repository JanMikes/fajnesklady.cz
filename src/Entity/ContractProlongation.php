<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Append-only audit row for every prolongation of a contract (spec 077).
 * Mirrors {@see ContractPriceChange}: written once by
 * {@see \App\Event\PersistContractProlongationHandler}, never updated.
 */
#[ORM\Entity]
#[ORM\Table(name: 'contract_prolongation')]
#[ORM\Index(columns: ['contract_id', 'prolonged_at'], name: 'idx_contract_prolongation_contract_prolonged')]
class ContractProlongation
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Contract::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private(set) Contract $contract,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $previousEndDate,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $newEndDate,
        #[ORM\Column]
        private(set) \DateTimeImmutable $prolongedAt,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private(set) ?User $prolongedBy,
        /* Billing snapshot AFTER the prolongation, so the admin history shows
         * what track the extension runs on (payment switches happen in the
         * same transaction). */
        #[ORM\Column(length: 20)]
        private(set) string $billingModeAfter,
        #[ORM\Column(length: 20, nullable: true)]
        private(set) ?string $paymentMethodAfter,
    ) {
    }
}
