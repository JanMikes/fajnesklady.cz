<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\DataFixtures\UserFixtures;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
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

    public function testOrderCreationReservesStorage(): void
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

        $this->assertSame(OrderStatus::RESERVED, $order->status);
        $this->assertSame(StorageStatus::RESERVED, $order->storage->status);
    }

    public function testOrderExpiresAfterSevenDays(): void
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

    public function testCancelledOrderReleasesStorage(): void
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
        // Maly box has pricePerMonth = 500 CZK = 50000 halere
        $this->assertSame(50000, $order->totalPrice);
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
        $this->assertSame(15000, $order->totalPrice);
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
