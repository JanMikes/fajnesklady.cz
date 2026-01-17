<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['landlord_id', 'year'])]
class LandlordInvoiceSequence
{
    #[ORM\Column]
    public private(set) int $lastNumber = 0;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) User $landlord,
        #[ORM\Column]
        private(set) int $year,
    ) {
    }

    public function getNextNumber(): int
    {
        return $this->lastNumber + 1;
    }

    public function incrementNumber(): void
    {
        ++$this->lastNumber;
    }

    /**
     * Format: {PREFIX}-{YEAR}-{XXXX}
     * Example: P001-2026-0001
     */
    public function formatInvoiceNumber(): string
    {
        $prefix = $this->landlord->selfBillingPrefix;

        if (null === $prefix) {
            throw new \LogicException(sprintf(
                'Landlord "%s" must have a selfBillingPrefix to generate invoice numbers.',
                $this->landlord->fullName,
            ));
        }

        return sprintf('%s-%d-%04d', $prefix, $this->year, $this->getNextNumber());
    }
}
