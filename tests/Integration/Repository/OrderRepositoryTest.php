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
use App\Enum\PaymentMethod;
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
            firstPaymentPrice: $price,
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
            firstPaymentPrice: 10000,
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
            firstPaymentPrice: 10000,
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
            firstPaymentPrice: 10000,
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

    public function testFindRecentAtPlaceHonoursLimit(): void
    {
        $tenant = $this->createUser('tenant-recent@test.com');
        $landlord = $this->createUser('landlord-recent@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'RECENT1', $landlord);

        for ($i = 0; $i < 4; ++$i) {
            $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+30 days'));
            $order->reserve(new \DateTimeImmutable());
        }

        $this->entityManager->flush();

        $this->assertCount(2, $this->repository->findRecentAtPlace($place, 2, null));
        $this->assertCount(4, $this->repository->findRecentAtPlace($place, 0, null));
    }

    public function testFindRecentAtPlaceFiltersByOwner(): void
    {
        $tenant = $this->createUser('tenant-recent-scope@test.com');
        $landlordA = $this->createUser('landlord-A@test.com');
        $landlordB = $this->createUser('landlord-B@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storageA = $this->createStorage($storageType, $place, 'OWNA', $landlordA);
        $storageB = $this->createStorage($storageType, $place, 'OWNB', $landlordB);

        $orderA = $this->createOrder($tenant, $storageA, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+30 days'));
        $orderA->reserve(new \DateTimeImmutable());
        $orderB = $this->createOrder($tenant, $storageB, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+30 days'));
        $orderB->reserve(new \DateTimeImmutable());

        $this->entityManager->flush();

        $forA = $this->repository->findRecentAtPlace($place, 0, $landlordA);
        $this->assertCount(1, $forA);
        $this->assertTrue($forA[0]->id->equals($orderA->id));

        $forB = $this->repository->findRecentAtPlace($place, 0, $landlordB);
        $this->assertCount(1, $forB);
        $this->assertTrue($forB[0]->id->equals($orderB->id));

        $this->assertCount(2, $this->repository->findRecentAtPlace($place, 0, null));
    }

    public function testFindUpcomingAtPlaceFiltersByStatusAndWindow(): void
    {
        $tenant = $this->createUser('tenant-upcoming@test.com');
        $landlord = $this->createUser('landlord-upcoming@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage1 = $this->createStorage($storageType, $place, 'UPC1', $landlord);
        $storage2 = $this->createStorage($storageType, $place, 'UPC2', $landlord);
        $storage3 = $this->createStorage($storageType, $place, 'UPC3', $landlord);
        $storage4 = $this->createStorage($storageType, $place, 'UPC4', $landlord);

        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        // Reserved within window
        $reserved = $this->createOrder($tenant, $storage1, $now->modify('+5 days'), $now->modify('+35 days'));
        $reserved->reserve($now);

        // Paid within window
        $paid = $this->createOrder($tenant, $storage2, $now->modify('+10 days'), $now->modify('+40 days'));
        $paid->reserve($now);
        $paid->markPaid($now);

        // Cancelled within window — excluded
        $cancelled = $this->createOrder($tenant, $storage3, $now->modify('+7 days'), $now->modify('+37 days'));
        $cancelled->reserve($now);
        $cancelled->cancel($now);

        // Reserved past window
        $tooFar = $this->createOrder($tenant, $storage4, $now->modify('+45 days'), $now->modify('+75 days'));
        $tooFar->reserve($now);

        $this->entityManager->flush();

        $upcoming = $this->repository->findUpcomingAtPlace($place, 30, $now, null);
        $ids = array_map(fn (Order $o): string => $o->id->toRfc4122(), $upcoming);

        $this->assertContains($reserved->id->toRfc4122(), $ids);
        $this->assertContains($paid->id->toRfc4122(), $ids);
        $this->assertNotContains($cancelled->id->toRfc4122(), $ids);
        $this->assertNotContains($tooFar->id->toRfc4122(), $ids);
    }

    public function testFindNextStartByStoragesPicksEarliestFutureBlockingOrder(): void
    {
        $tenant = $this->createUser('tenant-onextstart@test.com');
        $place = $this->createPlace();
        $st = $this->createStorageType();
        $storageA = $this->createStorage($st, $place, 'ONS1');
        $storageB = $this->createStorage($st, $place, 'ONS2');

        $now = new \DateTimeImmutable('2025-06-15');

        // storageA: two future blocking orders, the earlier one wins
        $reserved = $this->createOrder($tenant, $storageA, $now->modify('+10 days'), $now->modify('+40 days'));
        $reserved->reserve($now);
        $paid = $this->createOrder($tenant, $storageA, $now->modify('+5 days'), $now->modify('+35 days'));
        $paid->reserve($now);
        $paid->markPaid($now);

        // storageA: cancelled order — must NOT count
        $cancelled = $this->createOrder($tenant, $storageA, $now->modify('+2 days'), $now->modify('+8 days'));
        $cancelled->reserve($now);
        $cancelled->cancel($now);

        // storageB: only past order
        $past = $this->createOrder($tenant, $storageB, $now->modify('-30 days'), $now->modify('-1 day'));
        $past->reserve($now);

        $this->entityManager->flush();

        $result = $this->repository->findNextStartByStorages([$storageA, $storageB], $now);

        $this->assertEquals($now->modify('+5 days'), $result[$storageA->id->toRfc4122()]);
        $this->assertArrayNotHasKey($storageB->id->toRfc4122(), $result);
    }

    public function testFindNextStartByStoragesIsEmptyForEmptyInput(): void
    {
        $now = new \DateTimeImmutable('2025-06-15');

        $this->assertSame([], $this->repository->findNextStartByStorages([], $now));
    }

    public function testFindUnpaidSignedOnboardingMatchesAdminGoPayReservedSigned(): void
    {
        $tenant = $this->createUser('signed-unpaid@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'SU1');

        $now = new \DateTimeImmutable('2026-05-19 09:00:00');

        $matching = $this->createOrder($tenant, $storage, $now->modify('-2 days'), $now->modify('+30 days'));
        $matching->markAsAdminCreated();
        $matching->setPaymentMethod(PaymentMethod::GOPAY);
        $matching->reserve($now->modify('-3 days'));
        $matching->attachSignature(
            signaturePath: '/tmp/sig.png',
            signingMethod: \App\Enum\SigningMethod::DRAW,
            typedName: null,
            styleId: null,
            signingPlace: 'Praha',
            now: $now->modify('-2 days'),
        );
        $matching->extendExpiration($now->modify('+30 days'));

        // Non-matching variants
        $notAdmin = $this->createOrder($tenant, $this->createStorage($storageType, $place, 'SU2'), $now->modify('-2 days'));
        $notAdmin->setPaymentMethod(PaymentMethod::GOPAY);
        $notAdmin->reserve($now->modify('-2 days'));
        $notAdmin->attachSignature('/tmp/sig.png', \App\Enum\SigningMethod::DRAW, null, null, 'Praha', $now->modify('-2 days'));
        $notAdmin->extendExpiration($now->modify('+30 days'));

        $notGoPay = $this->createOrder($tenant, $this->createStorage($storageType, $place, 'SU3'), $now->modify('-2 days'));
        $notGoPay->markAsAdminCreated();
        $notGoPay->setPaymentMethod(PaymentMethod::EXTERNAL);
        $notGoPay->reserve($now->modify('-2 days'));
        $notGoPay->attachSignature('/tmp/sig.png', \App\Enum\SigningMethod::DRAW, null, null, 'Praha', $now->modify('-2 days'));
        $notGoPay->extendExpiration($now->modify('+30 days'));

        $notSigned = $this->createOrder($tenant, $this->createStorage($storageType, $place, 'SU4'), $now->modify('-2 days'));
        $notSigned->markAsAdminCreated();
        $notSigned->setPaymentMethod(PaymentMethod::GOPAY);
        $notSigned->reserve($now->modify('-2 days'));
        $notSigned->extendExpiration($now->modify('+30 days'));

        $expired = $this->createOrder($tenant, $this->createStorage($storageType, $place, 'SU5'), $now->modify('-2 days'));
        $expired->markAsAdminCreated();
        $expired->setPaymentMethod(PaymentMethod::GOPAY);
        $expired->reserve($now->modify('-2 days'));
        $expired->attachSignature('/tmp/sig.png', \App\Enum\SigningMethod::DRAW, null, null, 'Praha', $now->modify('-2 days'));
        $expired->extendExpiration($now->modify('-1 day'));

        $this->entityManager->flush();

        $found = $this->repository->findUnpaidSignedOnboarding($now);
        $foundIds = array_map(static fn (Order $o): string => $o->id->toRfc4122(), $found);

        $this->assertContains($matching->id->toRfc4122(), $foundIds);
        $this->assertNotContains($notAdmin->id->toRfc4122(), $foundIds);
        $this->assertNotContains($notGoPay->id->toRfc4122(), $foundIds);
        $this->assertNotContains($notSigned->id->toRfc4122(), $foundIds);
        $this->assertNotContains($expired->id->toRfc4122(), $foundIds);
    }
}
