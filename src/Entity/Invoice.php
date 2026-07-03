<?php

declare(strict_types=1);

namespace App\Entity;

use App\Event\InvoiceCreated;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
class Invoice implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Column(length: 500, nullable: true)]
    public private(set) ?string $pdfPath = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $emailedAt = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: Order::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Order $order,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) User $user,
        #[ORM\Column]
        private(set) int $fakturoidInvoiceId,
        #[ORM\Column(length: 50)]
        private(set) string $invoiceNumber,
        #[ORM\Column]
        private(set) int $amount,
        #[ORM\Column]
        private(set) \DateTimeImmutable $issuedAt,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
        // Set only for smluvní-pokuta invoices (spec 081); placed last so the
        // default keeps the pre-existing named-argument construction sites intact.
        #[ORM\ManyToOne(targetEntity: Fine::class)]
        #[ORM\JoinColumn(nullable: true)]
        private(set) ?Fine $fine = null,
    ) {
        $this->recordThat(new InvoiceCreated(
            invoiceId: $this->id,
            orderId: $this->order->id,
            occurredOn: $this->createdAt,
        ));
    }

    public function attachPdf(string $path): void
    {
        $this->pdfPath = $path;
    }

    public function hasPdf(): bool
    {
        return null !== $this->pdfPath;
    }

    public function markEmailed(\DateTimeImmutable $now): void
    {
        if (null === $this->emailedAt) {
            $this->emailedAt = $now;
        }
    }

    public function isEmailed(): bool
    {
        return null !== $this->emailedAt;
    }

    public function getAmountInCzk(): float
    {
        return $this->amount / 100;
    }
}
