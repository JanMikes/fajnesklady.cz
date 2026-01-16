<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Event\OrderCancelled;
use App\Event\OrderCompleted;
use App\Event\OrderCreated;
use App\Event\OrderExpired;
use App\Event\OrderPaid;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class OrderTest extends TestCase
{
    private function createUser(string $email = 'user@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'password', 'Test', 'User', new \DateTimeImmutable());
    }

    private function createOwner(): User
    {
        return new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', new \DateTimeImmutable());
    }

    private function createPlace(): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createStorageType(): StorageType
    {
        return new StorageType(
            id: Uuid::v7(),
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createStorage(?User $owner = null): Storage
    {
        return new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $this->createStorageType(),
            place: $this->createPlace(),
            createdAt: new \DateTimeImmutable(),
            owner: $owner,
        );
    }

    private function createOrder(
        ?User $user = null,
        ?Storage $storage = null,
        RentalType $rentalType = RentalType::LIMITED,
        ?PaymentFrequency $paymentFrequency = null,
        ?\DateTimeImmutable $startDate = null,
        ?\DateTimeImmutable $endDate = null,
        int $totalPrice = 50000,
        ?\DateTimeImmutable $createdAt = null,
    ): Order {
        $owner = $this->createOwner();
        $user ??= $this->createUser();
        $storage ??= $this->createStorage($owner);
        $createdAt ??= new \DateTimeImmutable();
        $startDate ??= new \DateTimeImmutable('+1 day');
        $expiresAt = $createdAt->modify('+7 days');

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: $rentalType,
            paymentFrequency: $paymentFrequency,
            startDate: $startDate,
            endDate: $endDate,
            totalPrice: $totalPrice,
            expiresAt: $expiresAt,
            createdAt: $createdAt,
        );
    }

    public function testCreateOrder(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->createUser();
        $owner = $this->createOwner();
        $storage = $this->createStorage($owner);
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');
        $expiresAt = $now->modify('+7 days');

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            totalPrice: 50000,
            expiresAt: $expiresAt,
            createdAt: $now,
        );

        $this->assertInstanceOf(Uuid::class, $order->id);
        $this->assertSame($user, $order->user);
        $this->assertSame($storage, $order->storage);
        $this->assertSame(RentalType::LIMITED, $order->rentalType);
        $this->assertSame(PaymentFrequency::MONTHLY, $order->paymentFrequency);
        $this->assertSame($startDate, $order->startDate);
        $this->assertSame($endDate, $order->endDate);
        $this->assertSame(50000, $order->totalPrice);
        $this->assertSame($expiresAt, $order->expiresAt);
        $this->assertSame($now, $order->createdAt);
        $this->assertSame(OrderStatus::CREATED, $order->status);
        $this->assertNull($order->paidAt);
        $this->assertNull($order->cancelledAt);
    }

    public function testCreateOrderRecordsEvent(): void
    {
        $order = $this->createOrder();
        $events = $order->popEvents();

        $this->assertCount(1, $events);
        $this->assertInstanceOf(OrderCreated::class, $events[0]);
        $this->assertTrue($order->id->equals($events[0]->orderId));
    }

    public function testDefaultStatusIsCreated(): void
    {
        $order = $this->createOrder();

        $this->assertSame(OrderStatus::CREATED, $order->status);
        $this->assertTrue($order->canBePaid());
        $this->assertTrue($order->canBeCancelled());
    }

    public function testReserve(): void
    {
        $order = $this->createOrder();
        $now = new \DateTimeImmutable();

        $order->reserve($now);

        $this->assertSame(OrderStatus::RESERVED, $order->status);
        $this->assertTrue($order->canBePaid());
        $this->assertTrue($order->canBeCancelled());
    }

    public function testReserveAlsoReservesStorage(): void
    {
        $order = $this->createOrder();
        $now = new \DateTimeImmutable();

        $order->reserve($now);

        $this->assertTrue($order->storage->isReserved());
    }

    public function testMarkAwaitingPayment(): void
    {
        $order = $this->createOrder();
        $now = new \DateTimeImmutable();

        $order->markAwaitingPayment($now);

        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->status);
        $this->assertTrue($order->canBePaid());
        $this->assertTrue($order->canBeCancelled());
    }

    public function testMarkPaid(): void
    {
        $order = $this->createOrder();
        $now = new \DateTimeImmutable();
        $order->popEvents(); // Clear created event

        $order->markPaid($now);

        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertSame($now, $order->paidAt);
        $this->assertFalse($order->canBePaid());
        $this->assertTrue($order->canBeCancelled());

        $events = $order->popEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(OrderPaid::class, $events[0]);
    }

    public function testComplete(): void
    {
        $order = $this->createOrder();
        $now = new \DateTimeImmutable();
        $contractId = Uuid::v7();
        $order->popEvents(); // Clear created event

        $order->complete($contractId, $now);

        $this->assertSame(OrderStatus::COMPLETED, $order->status);
        $this->assertFalse($order->canBePaid());
        $this->assertFalse($order->canBeCancelled());

        $events = $order->popEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(OrderCompleted::class, $events[0]);
        $this->assertTrue($order->id->equals($events[0]->orderId));
        $this->assertTrue($contractId->equals($events[0]->contractId));
    }

    public function testCompleteOccupiesStorage(): void
    {
        $order = $this->createOrder();
        $now = new \DateTimeImmutable();

        $order->complete(Uuid::v7(), $now);

        $this->assertTrue($order->storage->isOccupied());
    }

    public function testCancel(): void
    {
        $order = $this->createOrder();
        $now = new \DateTimeImmutable();
        $order->popEvents(); // Clear created event

        $order->cancel($now);

        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame($now, $order->cancelledAt);
        $this->assertFalse($order->canBePaid());
        $this->assertFalse($order->canBeCancelled());

        $events = $order->popEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(OrderCancelled::class, $events[0]);
    }

    public function testCancelReleasesStorage(): void
    {
        $order = $this->createOrder();
        $reserveTime = new \DateTimeImmutable('2024-01-01 10:00:00');
        $cancelTime = new \DateTimeImmutable('2024-01-01 11:00:00');

        $order->reserve($reserveTime);
        $this->assertTrue($order->storage->isReserved());

        $order->cancel($cancelTime);

        $this->assertTrue($order->storage->isAvailable());
    }

    public function testExpire(): void
    {
        $order = $this->createOrder();
        $now = new \DateTimeImmutable();
        $order->popEvents(); // Clear created event

        $order->expire($now);

        $this->assertSame(OrderStatus::EXPIRED, $order->status);
        $this->assertFalse($order->canBePaid());
        $this->assertFalse($order->canBeCancelled());

        $events = $order->popEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(OrderExpired::class, $events[0]);
    }

    public function testExpireReleasesStorage(): void
    {
        $order = $this->createOrder();
        $reserveTime = new \DateTimeImmutable('2024-01-01 10:00:00');
        $expireTime = new \DateTimeImmutable('2024-01-01 11:00:00');

        $order->reserve($reserveTime);
        $this->assertTrue($order->storage->isReserved());

        $order->expire($expireTime);

        $this->assertTrue($order->storage->isAvailable());
    }

    public function testIsExpiredReturnsTrueWhenExpired(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $order = $this->createOrder(createdAt: $createdAt);
        // expiresAt is 7 days after createdAt

        $afterExpiry = new \DateTimeImmutable('2024-01-09 10:00:00');

        $this->assertTrue($order->isExpired($afterExpiry));
    }

    public function testIsExpiredReturnsFalseBeforeExpiry(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $order = $this->createOrder(createdAt: $createdAt);

        $beforeExpiry = new \DateTimeImmutable('2024-01-05 10:00:00');

        $this->assertFalse($order->isExpired($beforeExpiry));
    }

    public function testIsExpiredReturnsFalseWhenAlreadyPaid(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $order = $this->createOrder(createdAt: $createdAt);
        $order->markPaid(new \DateTimeImmutable('2024-01-02'));

        $afterExpiry = new \DateTimeImmutable('2024-01-09 10:00:00');

        $this->assertFalse($order->isExpired($afterExpiry));
    }

    public function testIsExpiredReturnsFalseWhenInTerminalState(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $order = $this->createOrder(createdAt: $createdAt);
        $order->complete(Uuid::v7(), new \DateTimeImmutable('2024-01-02'));

        $afterExpiry = new \DateTimeImmutable('2024-01-09 10:00:00');

        $this->assertFalse($order->isExpired($afterExpiry));
    }

    public function testCanBePaidFromCreatedStatus(): void
    {
        $order = $this->createOrder();

        $this->assertTrue($order->canBePaid());
    }

    public function testCanBePaidFromReservedStatus(): void
    {
        $order = $this->createOrder();
        $order->reserve(new \DateTimeImmutable());

        $this->assertTrue($order->canBePaid());
    }

    public function testCanBePaidFromAwaitingPaymentStatus(): void
    {
        $order = $this->createOrder();
        $order->markAwaitingPayment(new \DateTimeImmutable());

        $this->assertTrue($order->canBePaid());
    }

    public function testCannotBePaidWhenAlreadyPaid(): void
    {
        $order = $this->createOrder();
        $order->markPaid(new \DateTimeImmutable());

        $this->assertFalse($order->canBePaid());
    }

    public function testCannotBePaidWhenCompleted(): void
    {
        $order = $this->createOrder();
        $order->complete(Uuid::v7(), new \DateTimeImmutable());

        $this->assertFalse($order->canBePaid());
    }

    public function testCannotBePaidWhenCancelled(): void
    {
        $order = $this->createOrder();
        $order->cancel(new \DateTimeImmutable());

        $this->assertFalse($order->canBePaid());
    }

    public function testCannotBePaidWhenExpired(): void
    {
        $order = $this->createOrder();
        $order->expire(new \DateTimeImmutable());

        $this->assertFalse($order->canBePaid());
    }

    public function testCanBeCancelledBeforeTerminalState(): void
    {
        $order = $this->createOrder();

        $this->assertTrue($order->canBeCancelled());

        $order->reserve(new \DateTimeImmutable());
        $this->assertTrue($order->canBeCancelled());

        $order->markAwaitingPayment(new \DateTimeImmutable());
        $this->assertTrue($order->canBeCancelled());

        $order->markPaid(new \DateTimeImmutable());
        $this->assertTrue($order->canBeCancelled());
    }

    public function testCannotBeCancelledInTerminalState(): void
    {
        $order1 = $this->createOrder();
        $order1->complete(Uuid::v7(), new \DateTimeImmutable());
        $this->assertFalse($order1->canBeCancelled());

        $order2 = $this->createOrder();
        $order2->cancel(new \DateTimeImmutable());
        $this->assertFalse($order2->canBeCancelled());

        $order3 = $this->createOrder();
        $order3->expire(new \DateTimeImmutable());
        $this->assertFalse($order3->canBeCancelled());
    }

    public function testIsUnlimited(): void
    {
        $unlimitedOrder = $this->createOrder(rentalType: RentalType::UNLIMITED);
        $this->assertTrue($unlimitedOrder->isUnlimited());

        $limitedOrder = $this->createOrder(rentalType: RentalType::LIMITED);
        $this->assertFalse($limitedOrder->isUnlimited());
    }

    public function testGetTotalPriceInCzk(): void
    {
        $order = $this->createOrder(totalPrice: 50000);

        $this->assertSame(500.0, $order->getTotalPriceInCzk());
    }

    public function testPriceStoredInHalire(): void
    {
        // 150 Kč = 15000 halířů
        $order = $this->createOrder(totalPrice: 15000);

        $this->assertSame(15000, $order->totalPrice);
        $this->assertSame(150.0, $order->getTotalPriceInCzk());
    }

    public function testOrderStateTransitionFlow(): void
    {
        // Full happy path: Created -> Reserved -> AwaitingPayment -> Paid -> Completed
        $order = $this->createOrder();
        $order->popEvents();

        $this->assertSame(OrderStatus::CREATED, $order->status);

        $order->reserve(new \DateTimeImmutable());
        $this->assertSame(OrderStatus::RESERVED, $order->status);
        $this->assertTrue($order->storage->isReserved());

        $order->markAwaitingPayment(new \DateTimeImmutable());
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->status);

        $order->markPaid(new \DateTimeImmutable());
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertNotNull($order->paidAt);

        $order->complete(Uuid::v7(), new \DateTimeImmutable());
        $this->assertSame(OrderStatus::COMPLETED, $order->status);
        $this->assertTrue($order->storage->isOccupied());

        $events = $order->popEvents();
        $this->assertCount(2, $events);
        $this->assertInstanceOf(OrderPaid::class, $events[0]);
        $this->assertInstanceOf(OrderCompleted::class, $events[1]);
    }
}
