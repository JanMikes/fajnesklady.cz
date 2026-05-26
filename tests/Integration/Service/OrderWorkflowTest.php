<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\DataFixtures\UserFixtures;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\ExpectedDuration;
use App\Enum\OrderStatus;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Enum\StorageStatus;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class OrderWorkflowTest extends KernelTestCase
{
    private OrderService $orderService;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->orderService = $container->get(OrderService::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->clock = $container->get(ClockInterface::class);
    }

    public function testOrderCreationKeepsStorageAvailable(): void
    {
        [$tenant, $storageType, $place] = $this->getFixtures();

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            $now,
        );

        $this->assertSame(OrderStatus::CREATED, $order->status);
        $this->assertSame(StorageStatus::AVAILABLE, $order->storage->status);
    }

    public function testOrderExpiresAfterPlaceWindow(): void
    {
        [$tenant, $storageType, $place] = $this->getFixtures();

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            $now,
        );

        $this->assertFalse($order->isExpired($now));
        $this->assertFalse($order->isExpired($now->modify('+'.($place->orderExpirationDays - 1).' days')));
        $this->assertTrue($order->isExpired($now->modify('+'.($place->orderExpirationDays + 1).' days')));

        $expireTime = $now->modify('+'.($place->orderExpirationDays + 1).' days');
        $this->orderService->expireOrder($order, $expireTime);

        $this->assertSame(OrderStatus::EXPIRED, $order->status);
        $this->assertSame(StorageStatus::AVAILABLE, $order->storage->status);
    }

    public function testExpireOverdueOrdersBatch(): void
    {
        [$tenant, $storageType, $place] = $this->getFixtures();

        $now = $this->clock->now();
        $pastDate = $now->modify('-10 days');
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');

        // Count expirable orders before creating new ones
        $expirableOrdersBefore = $this->countExpirableOrders($now);

        // Create two orders that are already expired (created 10 days ago)
        $order1 = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            $pastDate,
        );

        $order2 = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            $pastDate,
        );

        $this->entityManager->flush();

        // Expire all overdue orders
        $count = $this->orderService->expireOverdueOrders($now);

        // Should expire at least our 2 new orders plus any fixture orders
        $this->assertSame($expirableOrdersBefore + 2, $count);
        $this->assertSame(OrderStatus::EXPIRED, $order1->status);
        $this->assertSame(OrderStatus::EXPIRED, $order2->status);
    }

    public function testCancelledCreatedOrderKeepsStorageAvailable(): void
    {
        [$tenant, $storageType, $place] = $this->getFixtures();

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            $now,
        );

        $this->assertSame(StorageStatus::AVAILABLE, $order->storage->status);

        // Cancel the order (not yet reserved)
        $this->orderService->cancelOrder($order);

        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(StorageStatus::AVAILABLE, $order->storage->status);
    }

    public function testCancelledReservedOrderReleasesStorage(): void
    {
        [$tenant, $storageType, $place] = $this->getFixtures();

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            $now,
        );

        // Reserve the order (simulates contract signing)
        $order->reserve($now);
        $this->assertSame(StorageStatus::RESERVED, $order->storage->status);

        // Cancel the order
        $this->orderService->cancelOrder($order);

        $this->assertSame(OrderStatus::CANCELLED, $order->status);
        $this->assertSame(StorageStatus::AVAILABLE, $order->storage->status);
    }

    public function testCompletedOrderCreatesContractAndOccupiesStorage(): void
    {
        [$tenant, $storageType, $place] = $this->getFixtures();

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            $now,
        );

        // Reserve the order (simulates contract signing)
        $order->reserve($now);
        $this->assertSame(OrderStatus::RESERVED, $order->status);

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
        [$tenant, $storageType, $place] = $this->getFixtures();

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            $now,
        );

        $order->reserve($now);

        // Try to complete without payment - should throw
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Order must be paid before it can be completed.');
        $this->orderService->completeOrder($order);
    }

    public function testCannotCancelCompletedOrder(): void
    {
        [$tenant, $storageType, $place] = $this->getFixtures();

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            $now,
        );

        $order->reserve($now);

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
        [$tenant, $storageType, $place] = $this->getFixtures();

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            $now,
        );

        $order->reserve($now);

        // Cancel first
        $this->orderService->cancelOrder($order);

        // Try to pay - should throw
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Order cannot be paid in its current state.');
        $this->orderService->confirmPayment($order);
    }

    public function testUnlimitedRentalOrderCreation(): void
    {
        [$tenant, $storageType, $place] = $this->getFixtures();

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::UNLIMITED,
            $startDate,
            null, // No end date for unlimited
            $now,
        );

        $this->assertTrue($order->isUnlimited());
        $this->assertNull($order->endDate);
        // Maly box has defaultPricePerMonthLongTerm = 430 CZK = 43000 halere
        $this->assertSame(43000, $order->firstPaymentPrice);
    }

    public function testPreSelectedStorageWithOccupiedStatusButFreeWindowIsAccepted(): void
    {
        // Reproduces the production bug: storage status is OCCUPIED (a contract was created at some
        // point) but the contract's date window does NOT overlap the new booking. The pre-fix code
        // rejected this via `Storage::isAvailable()` (entity-status only); the fix routes the check
        // through StorageAvailabilityChecker which honors date windows.
        [$tenant, , $place] = $this->getFixtures();

        /** @var Storage $storage */
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.number = :n')
            ->setParameter('n', 'B3') // OCCUPIED, contract ends +29 days from MockClock (2025-07-14)
            ->getQuery()
            ->getSingleResult();
        $this->assertSame(StorageStatus::OCCUPIED, $storage->status);

        $now = $this->clock->now();
        // Booking sits entirely after the existing contract ends → no date overlap.
        $startDate = new \DateTimeImmutable('2025-08-01');
        $endDate = new \DateTimeImmutable('2025-09-01');

        $order = $this->orderService->createOrder(
            $tenant,
            $storage->storageType,
            $place,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            $now,
            preSelectedStorage: $storage,
        );

        $this->assertSame($storage->id, $order->storage->id);
        $this->assertSame(OrderStatus::CREATED, $order->status);
    }

    public function testPreSelectedStorageWithOverlappingContractIsRejected(): void
    {
        // Defense check: even with the new date-window logic, a real overlap must still throw.
        [$tenant, , $place] = $this->getFixtures();

        /** @var Storage $storage */
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.number = :n')
            ->setParameter('n', 'C1') // OCCUPIED, unlimited contract — always overlaps
            ->getQuery()
            ->getSingleResult();

        $now = $this->clock->now();
        $startDate = new \DateTimeImmutable('2025-08-01');
        $endDate = new \DateTimeImmutable('2025-09-01');

        $this->expectException(\App\Exception\NoStorageAvailable::class);
        $this->orderService->createOrder(
            $tenant,
            $storage->storageType,
            $place,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            $now,
            preSelectedStorage: $storage,
        );
    }

    public function testPriceCalculationForLimitedRental(): void
    {
        [$tenant, $storageType, $place] = $this->getFixtures();

        $now = $this->clock->now();

        // 7 days = 1 week
        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::LIMITED,
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-08'),
            $now,
        );

        // Maly box has pricePerWeek = 150 CZK = 15000 halere
        $this->assertSame(15000, $order->firstPaymentPrice);
    }

    public function testExpectedDurationIsStoredForUnlimitedRental(): void
    {
        [$tenant, $storageType, $place] = $this->getFixtures();

        $now = $this->clock->now();

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::UNLIMITED,
            $now->modify('+1 day'),
            null,
            $now,
            expectedDuration: ExpectedDuration::MEDIUM,
        );

        $this->assertSame(ExpectedDuration::MEDIUM, $order->expectedDuration);
    }

    public function testExpectedDurationIsDroppedForLimitedRental(): void
    {
        [$tenant, $storageType, $place] = $this->getFixtures();

        $now = $this->clock->now();

        // Defensive guard: even if a caller accidentally hands a non-null
        // expectedDuration to a LIMITED rental, OrderService MUST drop it on
        // the floor — the column is research-only and exclusive to UNLIMITED.
        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::LIMITED,
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-08'),
            $now,
            expectedDuration: ExpectedDuration::LONG,
        );

        $this->assertNull($order->expectedDuration);
    }

    private function countExpirableOrders(\DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->where('o.expiresAt < :now')
            ->andWhere('o.status NOT IN (:terminalStatuses)')
            ->andWhere('o.status != :paidStatus')
            ->setParameter('now', $now)
            ->setParameter('terminalStatuses', [OrderStatus::COMPLETED, OrderStatus::CANCELLED, OrderStatus::EXPIRED])
            ->setParameter('paidStatus', OrderStatus::PAID)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function testYearlyUnlimitedOrderPropagatesFrequencyToContract(): void
    {
        // Spec 045 — UNLIMITED yearly order. firstPaymentPrice is the yearly
        // amount; the contract inherits paymentFrequency = YEARLY and is forced
        // MANUAL_RECURRING. No GoPay token is stored, but CompleteOrderHandler
        // still has to seed nextBillingDate (or the manual cron stays blind).
        [$tenant, $storageType, $place] = $this->getFixtures();
        // Seed an explicit yearly rate so we exercise the discount path rather
        // than the monthly×12 fallback.
        $storageType->updateDetails(
            name: $storageType->name,
            innerWidth: $storageType->innerWidth,
            innerHeight: $storageType->innerHeight,
            innerLength: $storageType->innerLength,
            outerWidth: $storageType->outerWidth,
            outerHeight: $storageType->outerHeight,
            outerLength: $storageType->outerLength,
            defaultPricePerWeek: $storageType->defaultPricePerWeek,
            defaultPricePerMonth: $storageType->defaultPricePerMonth,
            defaultPricePerMonthLongTerm: $storageType->defaultPricePerMonthLongTerm,
            defaultPricePerYear: $storageType->defaultPricePerMonth * 10,
            description: $storageType->description,
            uniformStorages: $storageType->uniformStorages,
            now: $this->clock->now(),
        );

        $now = $this->clock->now();

        $order = $this->orderService->createOrder(
            user: $tenant,
            storageType: $storageType,
            place: $place,
            rentalType: RentalType::UNLIMITED,
            startDate: $now->modify('+1 day'),
            endDate: null,
            now: $now,
            paymentFrequency: PaymentFrequency::YEARLY,
            expectedDuration: ExpectedDuration::LONG,
        );
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);

        $this->assertSame(PaymentFrequency::YEARLY, $order->paymentFrequency);
        $this->assertSame($storageType->defaultPricePerMonth * 10, $order->firstPaymentPrice);

        $order->reserve($now);
        $this->orderService->processPayment($order);
        $this->orderService->confirmPayment($order);
        $contract = $this->orderService->completeOrder($order);

        $this->assertSame(PaymentFrequency::YEARLY, $contract->paymentFrequency);
        $this->assertSame(BillingMode::MANUAL_RECURRING, $contract->billingMode);
        $this->assertNull($contract->goPayParentPaymentId, 'yearly contracts store no GoPay token');
        $this->assertSame('+1 year', $contract->getBillingCadenceStep());
    }

    /**
     * @return array{User, StorageType, Place}
     */
    private function getFixtures(): array
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        return [$tenant, $storageType, $place];
    }
}
