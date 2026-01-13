<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\RentalType;
use App\Enum\StorageStatus;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class OrderWorkflowTest extends KernelTestCase
{
    private OrderService $orderService;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->orderService = $container->get(OrderService::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    private function createUser(string $email): User
    {
        $user = new User(Uuid::v7(), $email, 'password', 'Test', 'User', new \DateTimeImmutable());
        $this->entityManager->persist($user);

        return $user;
    }

    private function createPlace(User $owner): Place
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($place);

        return $place;
    }

    private function createStorageType(Place $place): StorageType
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test Type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            pricePerWeek: 10000,
            pricePerMonth: 35000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($storageType);

        return $storageType;
    }

    private function createStorage(StorageType $storageType, string $number): Storage
    {
        $storage = new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($storage);

        return $storage;
    }

    public function testOrderCreationReservesStorage(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $this->createStorage($storageType, 'A1');
        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+1 day');
        $endDate = new \DateTimeImmutable('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            RentalType::LIMITED,
            $startDate,
            $endDate,
        );

        $this->assertSame(OrderStatus::RESERVED, $order->status);
        $this->assertSame(StorageStatus::RESERVED, $order->storage->status);
    }

    public function testOrderExpiresAfterSevenDays(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $this->createStorage($storageType, 'A1');
        $this->entityManager->flush();

        $now = new \DateTimeImmutable();
        $startDate = new \DateTimeImmutable('+1 day');
        $endDate = new \DateTimeImmutable('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            null,
            $now,
        );

        // Verify order expires in 7 days
        $this->assertFalse($order->isExpired($now));
        $this->assertFalse($order->isExpired($now->modify('+6 days')));
        $this->assertTrue($order->isExpired($now->modify('+8 days')));

        // Expire the order
        $expireTime = $now->modify('+8 days');
        $this->orderService->expireOrder($order, $expireTime);

        $this->assertSame(OrderStatus::EXPIRED, $order->status);
        $this->assertSame(StorageStatus::AVAILABLE, $order->storage->status);
    }

    public function testExpireOverdueOrdersBatch(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $this->createStorage($storageType, 'A1');
        $this->createStorage($storageType, 'A2');
        $this->entityManager->flush();

        $now = new \DateTimeImmutable();
        $startDate = new \DateTimeImmutable('+1 day');
        $endDate = new \DateTimeImmutable('+30 days');

        // Create two orders
        $order1 = $this->orderService->createOrder(
            $tenant,
            $storageType,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            null,
            $now,
        );

        $order2 = $this->orderService->createOrder(
            $tenant,
            $storageType,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            null,
            $now,
        );

        $this->entityManager->flush();

        // Expire all overdue orders
        $expireTime = $now->modify('+8 days');
        $count = $this->orderService->expireOverdueOrders($expireTime);

        $this->assertSame(2, $count);
        $this->assertSame(OrderStatus::EXPIRED, $order1->status);
        $this->assertSame(OrderStatus::EXPIRED, $order2->status);
    }

    public function testCancelledOrderReleasesStorage(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $this->createStorage($storageType, 'A1');
        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+1 day');
        $endDate = new \DateTimeImmutable('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            RentalType::LIMITED,
            $startDate,
            $endDate,
        );

        $this->assertSame(StorageStatus::RESERVED, $order->storage->status);

        // Cancel the order
        $this->orderService->cancelOrder($order);

        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(StorageStatus::AVAILABLE, $order->storage->status);
    }

    public function testCompletedOrderCreatesContractAndOccupiesStorage(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $this->createStorage($storageType, 'A1');
        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+1 day');
        $endDate = new \DateTimeImmutable('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            RentalType::LIMITED,
            $startDate,
            $endDate,
        );

        // Process payment
        $this->orderService->processPayment($order);
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->status);

        // Confirm payment
        $this->orderService->confirmPayment($order);
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertNotNull($order->paidAt);

        // Complete order
        $contract = $this->orderService->completeOrder($order);

        $this->assertSame(OrderStatus::COMPLETED, $order->status);
        $this->assertSame(StorageStatus::OCCUPIED, $order->storage->status);
        $this->assertNotNull($contract);
        $this->assertTrue($contract->order->id->equals($order->id));
        $this->assertTrue($contract->user->id->equals($tenant->id));
        $this->assertTrue($contract->storage->id->equals($order->storage->id));
        $this->assertSame($order->rentalType, $contract->rentalType);
        $this->assertEquals($order->startDate, $contract->startDate);
        $this->assertEquals($order->endDate, $contract->endDate);
    }

    public function testCannotCompleteOrderWithoutPayment(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $this->createStorage($storageType, 'A1');
        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+1 day');
        $endDate = new \DateTimeImmutable('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            RentalType::LIMITED,
            $startDate,
            $endDate,
        );

        // Try to complete without payment - should throw
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Order must be paid before it can be completed.');
        $this->orderService->completeOrder($order);
    }

    public function testCannotCancelCompletedOrder(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $this->createStorage($storageType, 'A1');
        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+1 day');
        $endDate = new \DateTimeImmutable('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            RentalType::LIMITED,
            $startDate,
            $endDate,
        );

        // Pay and complete
        $this->orderService->confirmPayment($order);
        $this->orderService->completeOrder($order);

        // Try to cancel - should throw
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Order cannot be cancelled in its current state.');
        $this->orderService->cancelOrder($order);
    }

    public function testCannotPayCancelledOrder(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $this->createStorage($storageType, 'A1');
        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+1 day');
        $endDate = new \DateTimeImmutable('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            RentalType::LIMITED,
            $startDate,
            $endDate,
        );

        // Cancel first
        $this->orderService->cancelOrder($order);

        // Try to pay - should throw
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Order cannot be paid in its current state.');
        $this->orderService->confirmPayment($order);
    }

    public function testUnlimitedRentalOrderCreation(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $this->createStorage($storageType, 'A1');
        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+1 day');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            RentalType::UNLIMITED,
            $startDate,
            null, // No end date for unlimited
        );

        $this->assertTrue($order->isUnlimited());
        $this->assertNull($order->endDate);
        $this->assertSame(35000, $order->totalPrice); // Monthly price
    }

    public function testPriceCalculationForLimitedRental(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $this->createStorage($storageType, 'A1');
        $this->entityManager->flush();

        // 7 days = 1 week = 10000
        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            RentalType::LIMITED,
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-08'),
        );

        $this->assertSame(10000, $order->totalPrice);
    }
}
