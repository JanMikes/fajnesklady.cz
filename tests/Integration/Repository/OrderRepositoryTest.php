<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class OrderRepositoryTest extends KernelTestCase
{
    private OrderRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(OrderRepository::class);
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

    private function createPlace(): Place
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($place);

        return $place;
    }

    private function createStorageType(): StorageType
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $this->createPlace(),
            name: 'Test Type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($storageType);

        return $storageType;
    }

    private function createStorage(StorageType $storageType, Place $place, string $number, ?User $owner = null): Storage
    {
        $storage = new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
            owner: $owner,
        );
        $this->entityManager->persist($storage);

        return $storage;
    }

    private function createOrder(
        User $user,
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate = null,
        RentalType $rentalType = RentalType::LIMITED,
        int $price = 10000,
    ): Order {
        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: $rentalType,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            totalPrice: $price,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($order);

        return $order;
    }

    public function testFindOverlappingDetectsOverlappingLimitedPeriods(): void
    {
        $tenant = $this->createUser('tenant-overlap1@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'OL1');

        // Existing order: Jan 10-20
        $existingOrder = $this->createOrder(
            $tenant,
            $storage,
            new \DateTimeImmutable('2024-01-10'),
            new \DateTimeImmutable('2024-01-20'),
        );
        $existingOrder->reserve(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Check overlap: Jan 15-25 (overlaps with Jan 10-20)
        $overlapping = $this->repository->findOverlappingByStorage(
            $storage,
            new \DateTimeImmutable('2024-01-15'),
            new \DateTimeImmutable('2024-01-25'),
        );

        $this->assertCount(1, $overlapping);
        $this->assertEquals($existingOrder->id, $overlapping[0]->id);
    }

    public function testFindOverlappingDetectsNoOverlapForAdjacentPeriods(): void
    {
        $tenant = $this->createUser('tenant-adjacent@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'ADJ');

        // Existing order: Jan 1-10
        $existingOrder = $this->createOrder(
            $tenant,
            $storage,
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-10'),
        );
        $existingOrder->reserve(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Check overlap: Jan 11-20 (adjacent, not overlapping)
        $overlapping = $this->repository->findOverlappingByStorage(
            $storage,
            new \DateTimeImmutable('2024-01-11'),
            new \DateTimeImmutable('2024-01-20'),
        );

        $this->assertCount(0, $overlapping);
    }

    public function testFindOverlappingHandlesUnlimitedExistingPeriod(): void
    {
        $tenant = $this->createUser('tenant-unlimited1@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'UNL1');

        // Existing unlimited order starting Jan 1
        $existingOrder = $this->createOrder(
            $tenant,
            $storage,
            new \DateTimeImmutable('2024-01-01'),
            null,
            RentalType::UNLIMITED,
        );
        $existingOrder->reserve(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Check overlap: Feb 1-28 (any date after Jan 1 overlaps with unlimited)
        $overlapping = $this->repository->findOverlappingByStorage(
            $storage,
            new \DateTimeImmutable('2024-02-01'),
            new \DateTimeImmutable('2024-02-28'),
        );

        $this->assertCount(1, $overlapping);
    }

    public function testFindOverlappingHandlesUnlimitedRequestedPeriod(): void
    {
        $tenant = $this->createUser('tenant-unlimited2@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'UNL2');

        // Existing limited order: Feb 1-28
        $existingOrder = $this->createOrder(
            $tenant,
            $storage,
            new \DateTimeImmutable('2024-02-01'),
            new \DateTimeImmutable('2024-02-28'),
        );
        $existingOrder->reserve(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Request unlimited period starting Jan 15 (should overlap with Feb order)
        $overlapping = $this->repository->findOverlappingByStorage(
            $storage,
            new \DateTimeImmutable('2024-01-15'),
            null,
        );

        $this->assertCount(1, $overlapping);
    }

    public function testFindOverlappingExcludesSpecificOrder(): void
    {
        $tenant = $this->createUser('tenant-exclude@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'EXC');

        // Existing order: Jan 10-20
        $existingOrder = $this->createOrder(
            $tenant,
            $storage,
            new \DateTimeImmutable('2024-01-10'),
            new \DateTimeImmutable('2024-01-20'),
        );
        $existingOrder->reserve(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Check overlap but exclude the existing order
        $overlapping = $this->repository->findOverlappingByStorage(
            $storage,
            new \DateTimeImmutable('2024-01-15'),
            new \DateTimeImmutable('2024-01-25'),
            $existingOrder,
        );

        $this->assertCount(0, $overlapping);
    }

    public function testFindOverlappingOnlyConsidersActiveStatuses(): void
    {
        $tenant = $this->createUser('tenant-status@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'STAT');

        // Cancelled order: Jan 10-20
        $cancelledOrder = $this->createOrder(
            $tenant,
            $storage,
            new \DateTimeImmutable('2024-01-10'),
            new \DateTimeImmutable('2024-01-20'),
        );
        $cancelledOrder->reserve(new \DateTimeImmutable());
        $cancelledOrder->cancel(new \DateTimeImmutable());

        // Reserved order: Feb 10-20
        $reservedOrder = $this->createOrder(
            $tenant,
            $storage,
            new \DateTimeImmutable('2024-02-10'),
            new \DateTimeImmutable('2024-02-20'),
        );
        $reservedOrder->reserve(new \DateTimeImmutable());

        $this->entityManager->flush();

        // Check overlap with Jan period - cancelled order should be ignored
        $overlappingJan = $this->repository->findOverlappingByStorage(
            $storage,
            new \DateTimeImmutable('2024-01-15'),
            new \DateTimeImmutable('2024-01-25'),
        );

        // Check overlap with Feb period - reserved order should be found
        $overlappingFeb = $this->repository->findOverlappingByStorage(
            $storage,
            new \DateTimeImmutable('2024-02-15'),
            new \DateTimeImmutable('2024-02-25'),
        );

        $this->assertCount(0, $overlappingJan);
        $this->assertCount(1, $overlappingFeb);
    }

    public function testFindExpiredOrdersIgnoresPaid(): void
    {
        $tenant = $this->createUser('tenant-paid@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'PAI');

        // Create an order that has expired but is paid
        $paidOrder = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: new \DateTimeImmutable('+30 days'),
            endDate: new \DateTimeImmutable('+60 days'),
            totalPrice: 10000,
            expiresAt: new \DateTimeImmutable('-1 day'), // Already expired
            createdAt: new \DateTimeImmutable(),
        );
        $paidOrder->reserve(new \DateTimeImmutable());
        $paidOrder->markPaid(new \DateTimeImmutable());
        $this->entityManager->persist($paidOrder);
        $this->entityManager->flush();

        $expiredOrders = $this->repository->findExpiredOrders(new \DateTimeImmutable());

        $orderIds = array_map(fn (Order $o) => $o->id->toRfc4122(), $expiredOrders);
        $this->assertNotContains($paidOrder->id->toRfc4122(), $orderIds);
    }

    public function testFindExpiredOrdersIgnoresTerminalStatuses(): void
    {
        $tenant = $this->createUser('tenant-terminal@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'TRM');

        // Create an already cancelled order with expired timestamp
        $cancelledOrder = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: new \DateTimeImmutable('+30 days'),
            endDate: new \DateTimeImmutable('+60 days'),
            totalPrice: 10000,
            expiresAt: new \DateTimeImmutable('-1 day'),
            createdAt: new \DateTimeImmutable(),
        );
        $cancelledOrder->reserve(new \DateTimeImmutable());
        $cancelledOrder->cancel(new \DateTimeImmutable());
        $this->entityManager->persist($cancelledOrder);
        $this->entityManager->flush();

        $expiredOrders = $this->repository->findExpiredOrders(new \DateTimeImmutable());

        $orderIds = array_map(fn (Order $o) => $o->id->toRfc4122(), $expiredOrders);
        $this->assertNotContains($cancelledOrder->id->toRfc4122(), $orderIds);
    }

    public function testFindExpiredOrdersReturnsValidExpiredOrders(): void
    {
        $tenant = $this->createUser('tenant-expired@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'EXP');

        // Create an expired but not processed order
        $expiredOrder = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: new \DateTimeImmutable('+30 days'),
            endDate: new \DateTimeImmutable('+60 days'),
            totalPrice: 10000,
            expiresAt: new \DateTimeImmutable('-1 day'),
            createdAt: new \DateTimeImmutable(),
        );
        $expiredOrder->reserve(new \DateTimeImmutable());
        $this->entityManager->persist($expiredOrder);
        $this->entityManager->flush();

        $expiredOrders = $this->repository->findExpiredOrders(new \DateTimeImmutable());

        $orderIds = array_map(fn (Order $o) => $o->id->toRfc4122(), $expiredOrders);
        $this->assertContains($expiredOrder->id->toRfc4122(), $orderIds);
    }

    private function setOrderStatus(Order $order, OrderStatus $status): void
    {
        $reflection = new \ReflectionClass($order);
        $statusProperty = $reflection->getProperty('status');
        $statusProperty->setValue($order, $status);
    }

    public function testSumRevenueByLandlord(): void
    {
        $landlord = $this->createUser('landlord-revenue@test.com');
        $tenant = $this->createUser('tenant-revenue@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage1 = $this->createStorage($storageType, $place, 'REV1', $landlord);
        $storage2 = $this->createStorage($storageType, $place, 'REV2', $landlord);

        // Paid order - 150 CZK
        $paidOrder = $this->createOrder($tenant, $storage1, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+30 days'), RentalType::LIMITED, 15000);
        $this->setOrderStatus($paidOrder, OrderStatus::PAID);

        // Completed order - 200 CZK (use reflection to avoid event handlers)
        $completedOrder = $this->createOrder($tenant, $storage2, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+60 days'), RentalType::LIMITED, 20000);
        $this->setOrderStatus($completedOrder, OrderStatus::COMPLETED);

        $this->entityManager->flush();

        $revenue = $this->repository->sumRevenueByLandlord($landlord);

        // 15000 + 20000 = 35000 haléřů
        $this->assertSame(35000, $revenue);
    }

    public function testCountPaidByLandlord(): void
    {
        $landlord = $this->createUser('landlord-count@test.com');
        $tenant = $this->createUser('tenant-count@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage1 = $this->createStorage($storageType, $place, 'CNT1', $landlord);
        $storage2 = $this->createStorage($storageType, $place, 'CNT2', $landlord);
        $storage3 = $this->createStorage($storageType, $place, 'CNT3', $landlord);

        // Paid order (use reflection to set status directly)
        $paidOrder = $this->createOrder($tenant, $storage1, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+30 days'));
        $this->setOrderStatus($paidOrder, OrderStatus::PAID);

        // Completed order (use reflection to avoid event handlers)
        $completedOrder = $this->createOrder($tenant, $storage2, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+60 days'));
        $this->setOrderStatus($completedOrder, OrderStatus::COMPLETED);

        // Cancelled order (should not count)
        $cancelledOrder = $this->createOrder($tenant, $storage3, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+90 days'));
        $this->setOrderStatus($cancelledOrder, OrderStatus::CANCELLED);

        $this->entityManager->flush();

        $count = $this->repository->countPaidByLandlord($landlord);

        $this->assertSame(2, $count);
    }
}
