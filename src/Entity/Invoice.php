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

    public function getAmountInCzk(): float
    {
        return $this->amount / 100;
    }
}
