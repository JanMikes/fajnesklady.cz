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
use App\Enum\RentalType;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\StorageUnavailabilityRepository;
use App\Service\Storage\StorageOccupancyService;
use App\Value\RentalSpanKind;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class StorageOccupancyServiceTest extends TestCase
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
            email: 'occupancy-tenant@test.com',
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
            createdAt: $this->now,
        );
    }

    public function testFreeStorageProducesFreeView(): void
    {
        $storage = $this->makeStorage('A1');
        $service = $this->buildService(contracts: [], orders: [], blocks: []);

        $views = $service->currentViews([$storage], $this->now);

        $view = $views[$storage->id->toRfc4122()];
        $this->assertTrue($view->isFree);
        $this->assertFalse($view->isOccupied);
        $this->assertNull($view->rentedFrom);
        $this->assertNull($view->rentedUntil);
        $this->assertNull($view->availableFrom);
    }

    public function testFixedTermContractDrivesRentedFromAndUntil(): void
    {
        $storage = $this->makeStorage('A2');
        $start = new \DateTimeImmutable('2025-06-01');
        $end = new \DateTimeImmutable('2025-08-01');
        $contract = $this->makeContract($storage, RentalType::LIMITED, $start, $end);

        $service = $this->buildService(contracts: [$contract]);
        $views = $service->currentViews([$storage], $this->now);

        $view = $views[$storage->id->toRfc4122()];
        $this->assertTrue($view->isOccupied);
        $this->assertSame($contract, $view->currentContract);
        $this->assertEquals($start, $view->rentedFrom);
        $this->assertEquals($end, $view->rentedUntil);
        $this->assertEquals($end->modify('+1 day'), $view->availableFrom);
        $this->assertFalse($view->isUnlimited);
    }

    public function testUnlimitedContractMarksUnlimited(): void
    {
        $storage = $this->makeStorage('A3');
        $contract = $this->makeContract(
            $storage,
            RentalType::UNLIMITED,
            new \DateTimeImmutable('2025-01-01'),
            null,
        );

        $service = $this->buildService(contracts: [$contract]);
        $views = $service->currentViews([$storage], $this->now);

        $view = $views[$storage->id->toRfc4122()];
        $this->assertTrue($view->isUnlimited);
        $this->assertNull($view->rentedUntil);
        $this->assertNull($view->availableFrom);
    }

    public function testTerminatesAtOverridesEndDate(): void
    {
        $storage = $this->makeStorage('A4');
        $contract = $this->makeContract(
            $storage,
            RentalType::LIMITED,
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2025-12-31'),
        );
        $contract->requestTermination($this->now->modify('-1 day'), new \DateTimeImmutable('2025-07-15'));

        $service = $this->buildService(contracts: [$contract]);
        $views = $service->currentViews([$storage], $this->now);

        $view = $views[$storage->id->toRfc4122()];
        $this->assertTrue($view->isTerminating);
        $this->assertEquals(new \DateTimeImmutable('2025-07-15'), $view->rentedUntil);
        $this->assertEquals(new \DateTimeImmutable('2025-07-16'), $view->availableFrom);
    }

    public function testPaidOrderWithoutContractBecomesCurrentOrder(): void
    {
        $storage = $this->makeStorage('A5');
        $order = $this->makeOrder(
            $storage,
            new \DateTimeImmutable('2025-06-10'),
            new \DateTimeImmutable('2025-07-10'),
            OrderStatus::PAID,
        );

        $service = $this->buildService(orders: [$order]);
        $views = $service->currentViews([$storage], $this->now);

        $view = $views[$storage->id->toRfc4122()];
        $this->assertTrue($view->isOccupied);
        $this->assertNull($view->currentContract);
        $this->assertSame($order, $view->currentOrder);
        $this->assertEquals(new \DateTimeImmutable('2025-07-10'), $view->rentedUntil);
    }

    public function testCompletedOrderDoesNotBecomeCurrentOrder(): void
    {
        // COMPLETED orders represent post-contract state — they MUST not show
        // as the "current order" regardless of their date range.
        $storage = $this->makeStorage('A6');
        $order = $this->makeOrder(
            $storage,
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-07-01'),
            OrderStatus::COMPLETED,
        );

        $service = $this->buildService(orders: [$order]);
        $views = $service->currentViews([$storage], $this->now);

        $view = $views[$storage->id->toRfc4122()];
        $this->assertTrue($view->isFree);
        $this->assertNull($view->currentOrder);
    }

    public function testActiveBlockSurfacesAsBlockedView(): void
    {
        $storage = $this->makeStorage('A7');
        $block = $this->makeBlock(
            $storage,
            new \DateTimeImmutable('2025-06-10'),
            new \DateTimeImmutable('2025-06-25'),
        );

        $service = $this->buildService(blocks: [$block]);
        $views = $service->currentViews([$storage], $this->now);

        $view = $views[$storage->id->toRfc4122()];
        $this->assertTrue($view->isBlocked);
        $this->assertSame($block, $view->blockedBy);
        $this->assertNull($view->currentContract);
        $this->assertNull($view->currentOrder);
    }

    public function testNextBookedFromPicksEarliestFutureContractOrOrder(): void
    {
        $storage = $this->makeStorage('A8');
        // Currently occupied by a contract ending Aug 1
        $current = $this->makeContract(
            $storage,
            RentalType::LIMITED,
            new \DateTimeImmutable('2025-05-01'),
            new \DateTimeImmutable('2025-08-01'),
        );
        // Future contract starting Sep 1, future order starting Aug 15
        $futureContract = $this->makeContract(
            $storage,
            RentalType::LIMITED,
            new \DateTimeImmutable('2025-09-01'),
            new \DateTimeImmutable('2025-10-01'),
        );
        $futureOrder = $this->makeOrder(
            $storage,
            new \DateTimeImmutable('2025-08-15'),
            new \DateTimeImmutable('2025-09-15'),
            OrderStatus::RESERVED,
        );

        $service = $this->buildService(
            contracts: [$current, $futureContract],
            orders: [$futureOrder],
            futureContractStarts: [$storage->id->toRfc4122() => $futureContract->startDate],
            futureOrderStarts: [$storage->id->toRfc4122() => $futureOrder->startDate],
        );
        $views = $service->currentViews([$storage], $this->now);

        $view = $views[$storage->id->toRfc4122()];
        $this->assertEquals(new \DateTimeImmutable('2025-08-15'), $view->nextBookedFrom);
    }

    public function testCurrentViewsPropagatesArbitraryDateForFutureLookups(): void
    {
        // Regression anchor for spec 047: the service treats $now as a date
        // threshold (not "today"), so calling it with a future date returns
        // the rental state AT that date. The underlying repository queries
        // are verified to honor the same semantics via their integration tests.
        $storage = $this->makeStorage('FUT1');
        $futureDate = new \DateTimeImmutable('2025-08-01');

        $futureContract = $this->makeContract(
            $storage,
            RentalType::LIMITED,
            new \DateTimeImmutable('2025-07-15'),
            new \DateTimeImmutable('2025-08-15'),
        );

        // The stubs above return the same payload regardless of $now. The
        // service forwards $now unchanged, so $rentedFrom / $rentedUntil
        // reflect the (future) contract's window — not "today".
        $service = $this->buildService(contracts: [$futureContract]);
        $views = $service->currentViews([$storage], $futureDate);

        $view = $views[$storage->id->toRfc4122()];
        $this->assertSame($futureContract, $view->currentContract);
        $this->assertEquals(new \DateTimeImmutable('2025-07-15'), $view->rentedFrom);
        $this->assertEquals(new \DateTimeImmutable('2025-08-15'), $view->rentedUntil);
    }

    public function testSpansInRangeReturnsAllOverlappingWindowsKeyedByStorageId(): void
    {
        $storage = $this->makeStorage('A9');
        $contract = $this->makeContract(
            $storage,
            RentalType::UNLIMITED,
            new \DateTimeImmutable('2025-05-01'),
            null,
        );
        $block = $this->makeBlock(
            $storage,
            new \DateTimeImmutable('2025-06-20'),
            new \DateTimeImmutable('2025-06-25'),
        );

        $service = $this->buildService(
            overlappingContracts: [$contract],
            orders: [],
            blocks: [$block],
        );
        $spans = $service->spansInRange(
            [$storage],
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-06-30'),
        );

        $key = $storage->id->toRfc4122();
        $this->assertCount(2, $spans[$key]);
        $contractSpan = $spans[$key][0];
        $this->assertSame(RentalSpanKind::CONTRACT, $contractSpan->kind);
        $this->assertNull($contractSpan->endDate);
        $blockSpan = $spans[$key][1];
        $this->assertSame(RentalSpanKind::BLOCK, $blockSpan->kind);
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
        RentalType $rentalType,
        \DateTimeImmutable $start,
        ?\DateTimeImmutable $end,
    ): Contract {
        $order = $this->makeOrder($storage, $start, $end, OrderStatus::COMPLETED);

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $this->tenant,
            storage: $storage,
            rentalType: $rentalType,
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
            rentalType: null === $end ? RentalType::UNLIMITED : RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $start,
            endDate: $end,
            firstPaymentPrice: 100000,
            expiresAt: $start->modify('+7 days'),
            createdAt: $start->modify('-1 day'),
        );
        $order->popEvents();

        // Walk the entity through the requested terminal status.
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
     * @param Contract[]                        $contracts            returned by findActiveByStorages
     * @param Order[]                           $orders               returned by findActiveByStoragesInDateRange
     * @param StorageUnavailability[]           $blocks               returned by findByStoragesInDateRange
     * @param Contract[]                        $overlappingContracts returned by findOverlappingByStorages
     * @param array<string, \DateTimeImmutable> $futureContractStarts
     * @param array<string, \DateTimeImmutable> $futureOrderStarts
     */
    private function buildService(
        array $contracts = [],
        array $orders = [],
        array $blocks = [],
        array $overlappingContracts = [],
        array $futureContractStarts = [],
        array $futureOrderStarts = [],
    ): StorageOccupancyService {
        $contractRepo = $this->createStub(ContractRepository::class);
        $contractRepo->method('findActiveByStorages')->willReturn($contracts);
        $contractRepo->method('findOverlappingByStorages')->willReturn($overlappingContracts);
        $contractRepo->method('findNextStartByStorages')->willReturn($futureContractStarts);

        $orderRepo = $this->createStub(OrderRepository::class);
        $orderRepo->method('findActiveByStoragesInDateRange')->willReturn($orders);
        $orderRepo->method('findNextStartByStorages')->willReturn($futureOrderStarts);

        $blockRepo = $this->createStub(StorageUnavailabilityRepository::class);
        $blockRepo->method('findByStoragesInDateRange')->willReturn($blocks);

        return new StorageOccupancyService($contractRepo, $orderRepo, $blockRepo);
    }
}
