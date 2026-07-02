<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Storage;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\StorageUnavailability;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PaymentFrequency;
use App\Enum\StorageStatus;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\StorageUnavailabilityRepository;
use App\Service\Storage\StorageOccupancyService;
use App\Service\Storage\StorageStatusReconciler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class StorageStatusReconcilerTest extends TestCase
{
    private MockClock $clock;
    private \DateTimeImmutable $now;
    private User $tenant;
    private Place $place;
    private StorageType $storageType;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2025-06-15 12:00:00 UTC');
        $this->now = $this->clock->now();

        $this->tenant = new User(
            id: Uuid::v7(),
            email: 'reconcile-tenant@test.com',
            password: 'pwd',
            firstName: 'Karel',
            lastName: 'Novák',
            createdAt: $this->now,
        );

        $this->place = new Place(
            id: Uuid::v7(),
            name: 'Sklad Praha',
            address: 'Adresa 1',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $this->now,
        );

        $this->storageType = new StorageType(
            id: Uuid::v7(),
            place: $this->place,
            name: 'Malý',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: $this->now,
        );
    }

    public function testFreeStorageDerivesAvailable(): void
    {
        $storage = $this->makeStorage('A1');
        $reconciler = $this->buildReconciler();

        self::assertSame(
            StorageStatus::AVAILABLE,
            $reconciler->deriveStatus($storage, $this->now),
        );
    }

    public function testActiveContractDerivesOccupied(): void
    {
        $storage = $this->makeStorage('A2');
        $contract = $this->makeContract(
            $storage,
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-08-01'),
        );
        $reconciler = $this->buildReconciler(contracts: [$contract]);

        self::assertSame(
            StorageStatus::OCCUPIED,
            $reconciler->deriveStatus($storage, $this->now),
        );
    }

    public function testActiveBlockingOrderDerivesReserved(): void
    {
        $storage = $this->makeStorage('A3');
        $order = $this->makeOrder(
            $storage,
            new \DateTimeImmutable('2025-06-10'),
            new \DateTimeImmutable('2025-07-10'),
            OrderStatus::RESERVED,
        );
        $reconciler = $this->buildReconciler(orders: [$order]);

        self::assertSame(
            StorageStatus::RESERVED,
            $reconciler->deriveStatus($storage, $this->now),
        );
    }

    public function testPaidButUncontractedOrderDerivesReservedNotOccupied(): void
    {
        // The entity only flips to OCCUPIED on Order::complete (contract created).
        // A paid-but-not-yet-completed order keeps the unit RESERVED, so the
        // reconciler must too — otherwise dashboard "occupied" counts would jump
        // ahead of the contract.
        $storage = $this->makeStorage('A4');
        $order = $this->makeOrder(
            $storage,
            new \DateTimeImmutable('2025-06-10'),
            new \DateTimeImmutable('2025-07-10'),
            OrderStatus::PAID,
        );
        $reconciler = $this->buildReconciler(orders: [$order]);

        self::assertSame(
            StorageStatus::RESERVED,
            $reconciler->deriveStatus($storage, $this->now),
        );
    }

    public function testActiveManualBlockDerivesManuallyUnavailable(): void
    {
        $storage = $this->makeStorage('A5');
        $block = $this->makeBlock(
            $storage,
            new \DateTimeImmutable('2025-06-10'),
            new \DateTimeImmutable('2025-06-25'),
        );
        $reconciler = $this->buildReconciler(blocks: [$block]);

        self::assertSame(
            StorageStatus::MANUALLY_UNAVAILABLE,
            $reconciler->deriveStatus($storage, $this->now),
        );
    }

    public function testCompletedOrderWithoutContractDerivesAvailable(): void
    {
        // A COMPLETED order represents post-contract state; with no live contract
        // present the unit is genuinely free.
        $storage = $this->makeStorage('A6');
        $order = $this->makeOrder(
            $storage,
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-07-01'),
            OrderStatus::COMPLETED,
        );
        $reconciler = $this->buildReconciler(orders: [$order]);

        self::assertSame(
            StorageStatus::AVAILABLE,
            $reconciler->deriveStatus($storage, $this->now),
        );
    }

    public function testContractTakesPrecedenceOverBlock(): void
    {
        $storage = $this->makeStorage('A7');
        $contract = $this->makeContract(
            $storage,
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-08-01'),
        );
        $block = $this->makeBlock(
            $storage,
            new \DateTimeImmutable('2025-06-10'),
            new \DateTimeImmutable('2025-06-25'),
        );
        $reconciler = $this->buildReconciler(contracts: [$contract], blocks: [$block]);

        self::assertSame(
            StorageStatus::OCCUPIED,
            $reconciler->deriveStatus($storage, $this->now),
        );
    }

    public function testDeriveStatusesKeyedByStorageId(): void
    {
        $free = $this->makeStorage('B1');
        $occupied = $this->makeStorage('B2');
        $contract = $this->makeContract(
            $occupied,
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-08-01'),
        );
        $reconciler = $this->buildReconciler(contracts: [$contract]);

        $statuses = $reconciler->deriveStatuses([$free, $occupied], $this->now);

        self::assertSame(StorageStatus::AVAILABLE, $statuses[$free->id->toRfc4122()]);
        self::assertSame(StorageStatus::OCCUPIED, $statuses[$occupied->id->toRfc4122()]);
    }

    private function makeStorage(string $number): Storage
    {
        return new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $this->storageType,
            place: $this->place,
            createdAt: $this->now,
        );
    }

    private function makeContract(
        Storage $storage,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
    ): Contract {
        $order = $this->makeOrder($storage, $start, $end, OrderStatus::COMPLETED);

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $this->tenant,
            storage: $storage,
            startDate: $start,
            endDate: $end,
            createdAt: $start,
        );
    }

    private function makeOrder(
        Storage $storage,
        \DateTimeImmutable $start,
        ?\DateTimeImmutable $end,
        OrderStatus $status,
    ): Order {
        $order = new Order(
            id: Uuid::v7(),
            user: $this->tenant,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $start,
            endDate: $end,
            firstPaymentPrice: 100000,
            expiresAt: $start->modify('+7 days'),
            createdAt: $start->modify('-1 day'),
        );
        $order->popEvents();

        $statesByTarget = [
            OrderStatus::CREATED->value => [],
            OrderStatus::RESERVED->value => ['reserve'],
            OrderStatus::AWAITING_PAYMENT->value => ['reserve', 'markAwaitingPayment'],
            OrderStatus::PAID->value => ['reserve', 'markAwaitingPayment', 'markPaid'],
            OrderStatus::COMPLETED->value => ['reserve', 'markAwaitingPayment', 'markPaid'],
        ];

        foreach ($statesByTarget[$status->value] ?? [] as $method) {
            $order->{$method}($start);
        }

        if (OrderStatus::COMPLETED === $status) {
            $order->complete(Uuid::v7(), $start);
        }

        $order->popEvents();

        return $order;
    }

    private function makeBlock(
        Storage $storage,
        \DateTimeImmutable $start,
        ?\DateTimeImmutable $end,
    ): StorageUnavailability {
        return new StorageUnavailability(
            id: Uuid::v7(),
            storage: $storage,
            startDate: $start,
            endDate: $end,
            reason: 'Test block',
            createdBy: $this->tenant,
            createdAt: $start,
        );
    }

    /**
     * @param Contract[]              $contracts returned by findActiveByStorages
     * @param Order[]                 $orders    returned by findActiveByStoragesInDateRange
     * @param StorageUnavailability[] $blocks    returned by findByStoragesInDateRange
     */
    private function buildReconciler(
        array $contracts = [],
        array $orders = [],
        array $blocks = [],
    ): StorageStatusReconciler {
        $contractRepo = $this->createStub(ContractRepository::class);
        $contractRepo->method('findActiveByStorages')->willReturn($contracts);
        $contractRepo->method('findOverlappingByStorages')->willReturn([]);
        $contractRepo->method('findNextStartByStorages')->willReturn([]);

        $orderRepo = $this->createStub(OrderRepository::class);
        $orderRepo->method('findActiveByStoragesInDateRange')->willReturn($orders);
        $orderRepo->method('findNextStartByStorages')->willReturn([]);

        $blockRepo = $this->createStub(StorageUnavailabilityRepository::class);
        $blockRepo->method('findByStoragesInDateRange')->willReturn($blocks);

        return new StorageStatusReconciler(
            new StorageOccupancyService($contractRepo, $orderRepo, $blockRepo),
        );
    }
}
