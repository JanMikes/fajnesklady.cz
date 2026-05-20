<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageUnavailabilityRepository;
use App\Repository\UserRepository;
use App\Service\Storage\StorageOccupancyService;
use App\Twig\Components\PlaceOccupancyMap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

final class PlaceOccupancyMapTest extends TestCase
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
            email: 'occ-component-tenant@test.com',
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

    public function testMapDataPayloadSerialisesPerStorageOccupancyFacts(): void
    {
        $storage = $this->makeStorage('M1');
        $contract = $this->makeContract(
            $storage,
            RentalType::LIMITED,
            new \DateTimeImmutable('2025-06-01'),
            new \DateTimeImmutable('2025-06-15'),
        );

        $component = $this->makeComponent([$storage], contracts: [$contract]);
        $component->placeId = $this->place->id->toRfc4122();
        $component->viewDate = '2025-06-15';

        $data = $component->getMapData();
        /** @var array<int, array<string, mixed>> $payload */
        $payload = json_decode($data['storagesJson'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(1, $payload);
        $entry = $payload[0];
        $this->assertSame($storage->id->toRfc4122(), $entry['id']);
        $this->assertSame('M1', $entry['number']);
        $this->assertSame('occupied', $entry['status']);
        $this->assertSame('Karel Novák', $entry['tenantName']);
        $this->assertSame('2025-06-15', $entry['rentedUntil']);
        $this->assertTrue($entry['endsOnViewDate']);
        $this->assertFalse($entry['startsOnViewDate']);
        $this->assertFalse($entry['isUnlimited']);
        $this->assertStringContainsString('/admin/orders/', $entry['orderUrl']);
    }

    public function testMapDataResolvesFutureViewDateThroughClockAndService(): void
    {
        $storage = $this->makeStorage('M2');

        // No active contract / order / block — the payload should describe a free storage.
        $component = $this->makeComponent([$storage]);
        $component->placeId = $this->place->id->toRfc4122();
        $component->viewDate = '2025-09-15';

        $data = $component->getMapData();
        /** @var array<int, array<string, mixed>> $payload */
        $payload = json_decode($data['storagesJson'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('available', $payload[0]['status']);
        $this->assertNull($payload[0]['tenantName']);
        $this->assertEquals(new \DateTimeImmutable('2025-09-15'), $data['viewDate']);
    }

    public function testSetTodayLiveActionResetsViewDateToClockNow(): void
    {
        $component = $this->makeComponent([]);
        $component->viewDate = '2025-09-15';

        $component->setToday();

        $this->assertSame('2025-06-15', $component->viewDate);
    }

    public function testShiftDaysLiveActionMovesRelativeToCurrentValue(): void
    {
        $component = $this->makeComponent([]);
        $component->viewDate = '2025-06-15';

        $component->shiftDays(7);

        $this->assertSame('2025-06-22', $component->viewDate);
    }

    /**
     * @param Storage[]  $storages
     * @param Contract[] $contracts
     * @param Order[]    $orders
     */
    private function makeComponent(array $storages, array $contracts = [], array $orders = []): PlaceOccupancyMap
    {
        $placeRepo = $this->createStub(PlaceRepository::class);
        $placeRepo->method('get')->willReturn($this->place);

        $storageRepo = $this->createStub(StorageRepository::class);
        $storageRepo->method('findByPlace')->willReturn($storages);
        $storageRepo->method('findByOwnerAndPlace')->willReturn($storages);

        $userRepo = $this->createStub(UserRepository::class);
        $userRepo->method('get')->willReturn($this->tenant);

        $contractRepo = $this->createStub(ContractRepository::class);
        $contractRepo->method('findActiveByStorages')->willReturn($contracts);
        $contractRepo->method('findNextStartByStorages')->willReturn([]);

        $orderRepo = $this->createStub(OrderRepository::class);
        $orderRepo->method('findActiveByStoragesInDateRange')->willReturn($orders);
        $orderRepo->method('findNextStartByStorages')->willReturn([]);

        $blockRepo = $this->createStub(StorageUnavailabilityRepository::class);
        $blockRepo->method('findByStoragesInDateRange')->willReturn([]);

        $service = new StorageOccupancyService($contractRepo, $orderRepo, $blockRepo);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $params): string => '/portal/'.('portal_landlord_order_detail' === $route ? 'landlord' : 'admin').'/orders/'.$params['id'],
        );

        return new PlaceOccupancyMap(
            $placeRepo,
            $storageRepo,
            $userRepo,
            $service,
            $this->clock,
            $urlGenerator,
        );
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
        $order = new Order(
            id: Uuid::v7(),
            user: $this->tenant,
            storage: $storage,
            rentalType: $rentalType,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $start,
            endDate: $end,
            firstPaymentPrice: 100000,
            expiresAt: $start->modify('+7 days'),
            createdAt: $start->modify('-1 day'),
        );
        $order->popEvents();
        $order->reserve($start);
        $order->markAwaitingPayment($start);
        $order->markPaid($start);
        $order->popEvents();
        $contractId = Uuid::v7();
        $order->complete($contractId, $start);
        $order->popEvents();

        return new Contract(
            id: $contractId,
            order: $order,
            user: $this->tenant,
            storage: $storage,
            rentalType: $rentalType,
            startDate: $start,
            endDate: $end,
            createdAt: $start,
        );
    }
}
