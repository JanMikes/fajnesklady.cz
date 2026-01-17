<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['landlord_id', 'year', 'month'])]
class SelfBillingInvoice
{
    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $pdfPath = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $fakturoidInvoiceId = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) User $landlord,
        #[ORM\Column]
        private(set) int $year,
        #[ORM\Column]
        private(set) int $month,
        #[ORM\Column(length: 20)]
        private(set) string $invoiceNumber,
        #[ORM\Column]
        private(set) int $grossAmount,
        #[ORM\Column(type: Types::DECIMAL, precision: 5, scale: 2)]
        private(set) string $commissionRate,
        #[ORM\Column]
        private(set) int $netAmount,
        #[ORM\Column]
        private(set) \DateTimeImmutable $issuedAt,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
    }

    public function attachPdf(string $path): void
    {
        $this->pdfPath = $path;
    }

    public function setFakturoidInvoiceId(int $invoiceId): void
    {
        $this->fakturoidInvoiceId = $invoiceId;
    }

    public function hasPdf(): bool
    {
        return null !== $this->pdfPath;
    }

    public function getGrossAmountInCzk(): float
    {
        return $this->grossAmount / 100;
    }

    public function getNetAmountInCzk(): float
    {
        return $this->netAmount / 100;
    }

    public function getPeriodFormatted(): string
    {
        return sprintf('%02d/%d', $this->month, $this->year);
    }
}
