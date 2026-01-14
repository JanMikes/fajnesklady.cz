<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OrderStatus;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Event\OrderCancelled;
use App\Event\OrderCompleted;
use App\Event\OrderCreated;
use App\Event\OrderExpired;
use App\Event\OrderPaid;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'orders')]
class Order implements EntityWithEvents
{
    use HasEvents;

    #[ORM\Column(length: 30, enumType: OrderStatus::class)]
    public private(set) OrderStatus $status;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $goPayPaymentId = null;

    #[ORM\Column(nullable: true)]
    public private(set) ?int $goPayParentPaymentId = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME, unique: true)]
        private(set) Uuid $id,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) User $user,
        #[ORM\ManyToOne(targetEntity: Storage::class)]
        #[ORM\JoinColumn(nullable: false)]
        private(set) Storage $storage,
        #[ORM\Column(length: 20, enumType: RentalType::class)]
        private(set) RentalType $rentalType,
        #[ORM\Column(length: 20, enumType: PaymentFrequency::class, nullable: true)]
        private(set) ?PaymentFrequency $paymentFrequency,
        #[ORM\Column(type: Types::DATE_IMMUTABLE)]
        private(set) \DateTimeImmutable $startDate,
        #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
        private(set) ?\DateTimeImmutable $endDate,
        #[ORM\Column]
        private(set) int $totalPrice,
        #[ORM\Column]
        private(set) \DateTimeImmutable $expiresAt,
        #[ORM\Column]
        private(set) \DateTimeImmutable $createdAt,
    ) {
        $this->status = OrderStatus::CREATED;

        $this->recordThat(new OrderCreated(
            orderId: $this->id,
            userId: $this->user->id,
            storageId: $this->storage->id,
            totalPrice: $this->totalPrice,
            occurredOn: $this->createdAt,
        ));
    }

    public function reserve(\DateTimeImmutable $now): void
    {
        $this->status = OrderStatus::RESERVED;
        $this->storage->reserve($now);
    }

    public function markAwaitingPayment(\DateTimeImmutable $now): void
    {
        $this->status = OrderStatus::AWAITING_PAYMENT;
    }

    public function markPaid(\DateTimeImmutable $now): void
    {
        $this->status = OrderStatus::PAID;
        $this->paidAt = $now;

        $this->recordThat(new OrderPaid(
            orderId: $this->id,
            occurredOn: $now,
        ));
    }

    public function complete(Uuid $contractId, \DateTimeImmutable $now): void
    {
        $this->status = OrderStatus::COMPLETED;
        $this->storage->occupy($now);

        $this->recordThat(new OrderCompleted(
            orderId: $this->id,
            contractId: $contractId,
            occurredOn: $now,
        ));
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        $this->status = OrderStatus::CANCELLED;
        $this->cancelledAt = $now;
        $this->storage->release($now);

        $this->recordThat(new OrderCancelled(
            orderId: $this->id,
            occurredOn: $now,
        ));
    }

    public function expire(\DateTimeImmutable $now): void
    {
        $this->status = OrderStatus::EXPIRED;
        $this->storage->release($now);

        $this->recordThat(new OrderExpired(
            orderId: $this->id,
            occurredOn: $now,
        ));
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt && !$this->status->isTerminal() && OrderStatus::PAID !== $this->status;
    }

    public function canBePaid(): bool
    {
        return in_array($this->status, [OrderStatus::CREATED, OrderStatus::RESERVED, OrderStatus::AWAITING_PAYMENT], true);
    }

    public function canBeCancelled(): bool
    {
        return !$this->status->isTerminal();
    }

    public function canBeCompleted(): bool
    {
        return OrderStatus::PAID === $this->status;
    }

    public function isUnlimited(): bool
    {
        return RentalType::UNLIMITED === $this->rentalType;
    }

    public function getTotalPriceInCzk(): float
    {
        return $this->totalPrice / 100;
    }

    public function setGoPayPaymentId(int $paymentId): void
    {
        $this->goPayPaymentId = $paymentId;
    }

    public function setGoPayParentPaymentId(int $parentPaymentId): void
    {
        $this->goPayParentPaymentId = $parentPaymentId;
    }

    public function hasRecurringPaymentSetup(): bool
    {
        return null !== $this->goPayParentPaymentId;
    }
}
