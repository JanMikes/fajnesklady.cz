<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\CreateStorageUnavailabilityCommand;
use App\Command\CreateStorageUnavailabilityHandler;
use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Exception\StorageHasActiveRental;
use App\Repository\ContractRepository;
use App\Repository\OrderRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageUnavailabilityRepository;
use App\Repository\UserRepository;
use App\Service\Identity\ProvideIdentity;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

class CreateStorageUnavailabilityHandlerTest extends TestCase
{
    private StorageRepository&Stub $storageRepository;
    private StorageUnavailabilityRepository&Stub $unavailabilityRepository;
    private UserRepository&Stub $userRepository;
    private ContractRepository&Stub $contractRepository;
    private OrderRepository&Stub $orderRepository;
    private ClockInterface&Stub $clock;
    private ProvideIdentity&Stub $identityProvider;
    private CreateStorageUnavailabilityHandler $handler;

    private Storage $storage;
    private User $user;

    protected function setUp(): void
    {
        $this->storageRepository = $this->createStub(StorageRepository::class);
        $this->unavailabilityRepository = $this->createStub(StorageUnavailabilityRepository::class);
        $this->userRepository = $this->createStub(UserRepository::class);
        $this->contractRepository = $this->createStub(ContractRepository::class);
        $this->orderRepository = $this->createStub(OrderRepository::class);
        $this->clock = $this->createStub(ClockInterface::class);
        $this->identityProvider = $this->createStub(ProvideIdentity::class);

        $this->handler = new CreateStorageUnavailabilityHandler(
            $this->storageRepository,
            $this->unavailabilityRepository,
            $this->userRepository,
            $this->contractRepository,
            $this->orderRepository,
            $this->clock,
            $this->identityProvider,
        );

        $this->user = new User(Uuid::v7(), 'landlord@example.com', 'password', 'Test', 'User', new \DateTimeImmutable());

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: new \DateTimeImmutable(),
        );

        $this->storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
            owner: $this->user,
        );

        $this->storageRepository->method('get')->willReturn($this->storage);
        $this->userRepository->method('get')->willReturn($this->user);
        $this->identityProvider->method('next')->willReturn(Uuid::v7());
        $this->clock->method('now')->willReturn(new \DateTimeImmutable('2025-06-15 12:00:00'));
    }

    public function testBlockingSucceedsWhenNoOverlappingRentals(): void
    {
        $this->contractRepository->method('findOverlappingByStorage')->willReturn([]);
        $this->orderRepository->method('findOverlappingByStorage')->willReturn([]);

        $command = new CreateStorageUnavailabilityCommand(
            storageId: $this->storage->id,
            startDate: new \DateTimeImmutable('2025-07-01'),
            endDate: new \DateTimeImmutable('2025-07-31'),
            reason: 'Údržba',
            createdById: $this->user->id,
        );

        $unavailability = ($this->handler)($command);

        $this->assertSame($this->storage, $unavailability->storage);
    }

    public function testBlockingFailsWhenOverlappingWithActiveContract(): void
    {
        $order = $this->createOrder(
            new \DateTimeImmutable('2025-07-01'),
            new \DateTimeImmutable('2025-08-01'),
        );

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $this->user,
            storage: $this->storage,
            rentalType: RentalType::LIMITED,
            startDate: new \DateTimeImmutable('2025-07-01'),
            endDate: new \DateTimeImmutable('2025-08-01'),
            createdAt: new \DateTimeImmutable(),
        );

        $this->contractRepository->method('findOverlappingByStorage')->willReturn([$contract]);
        $this->orderRepository->method('findOverlappingByStorage')->willReturn([]);

        $command = new CreateStorageUnavailabilityCommand(
            storageId: $this->storage->id,
            startDate: new \DateTimeImmutable('2025-07-15'),
            endDate: new \DateTimeImmutable('2025-07-20'),
            reason: 'Údržba',
            createdById: $this->user->id,
        );

        $this->expectException(StorageHasActiveRental::class);

        ($this->handler)($command);
    }

    public function testBlockingFailsWhenOverlappingWithActiveOrder(): void
    {
        $order = $this->createOrder(
            new \DateTimeImmutable('2025-07-01'),
            new \DateTimeImmutable('2025-08-01'),
        );

        $this->contractRepository->method('findOverlappingByStorage')->willReturn([]);
        $this->orderRepository->method('findOverlappingByStorage')->willReturn([$order]);

        $command = new CreateStorageUnavailabilityCommand(
            storageId: $this->storage->id,
            startDate: new \DateTimeImmutable('2025-07-15'),
            endDate: new \DateTimeImmutable('2025-07-20'),
            reason: 'Údržba',
            createdById: $this->user->id,
        );

        $this->expectException(StorageHasActiveRental::class);

        ($this->handler)($command);
    }

    public function testBlockingFailsWhenOverlappingWithUnlimitedContract(): void
    {
        $order = $this->createOrder(
            new \DateTimeImmutable('2025-06-01'),
            null,
            RentalType::UNLIMITED,
        );

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $this->user,
            storage: $this->storage,
            rentalType: RentalType::UNLIMITED,
            startDate: new \DateTimeImmutable('2025-06-01'),
            endDate: null,
            createdAt: new \DateTimeImmutable(),
        );

        $this->contractRepository->method('findOverlappingByStorage')->willReturn([$contract]);
        $this->orderRepository->method('findOverlappingByStorage')->willReturn([]);

        // Trying to block any future period should fail because the unlimited contract never ends
        $command = new CreateStorageUnavailabilityCommand(
            storageId: $this->storage->id,
            startDate: new \DateTimeImmutable('2025-09-01'),
            endDate: new \DateTimeImmutable('2025-09-30'),
            reason: 'Údržba',
            createdById: $this->user->id,
        );

        $this->expectException(StorageHasActiveRental::class);

        ($this->handler)($command);
    }

    public function testBlockingFailsWithIndefiniteBlockOverlappingContract(): void
    {
        $order = $this->createOrder(
            new \DateTimeImmutable('2025-08-01'),
            new \DateTimeImmutable('2025-09-01'),
        );

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $this->user,
            storage: $this->storage,
            rentalType: RentalType::LIMITED,
            startDate: new \DateTimeImmutable('2025-08-01'),
            endDate: new \DateTimeImmutable('2025-09-01'),
            createdAt: new \DateTimeImmutable(),
        );

        $this->contractRepository->method('findOverlappingByStorage')->willReturn([$contract]);
        $this->orderRepository->method('findOverlappingByStorage')->willReturn([]);

        // Indefinite block starting before the contract should fail
        $command = new CreateStorageUnavailabilityCommand(
            storageId: $this->storage->id,
            startDate: new \DateTimeImmutable('2025-07-01'),
            endDate: null,
            reason: 'Údržba',
            createdById: $this->user->id,
        );

        $this->expectException(StorageHasActiveRental::class);

        ($this->handler)($command);
    }

    public function testBlockingSucceedsWhenPeriodDoesNotOverlapWithContract(): void
    {
        $this->contractRepository->method('findOverlappingByStorage')->willReturn([]);
        $this->orderRepository->method('findOverlappingByStorage')->willReturn([]);

        // Block period: July 1-15, Contract: August 1-31 (no overlap)
        $command = new CreateStorageUnavailabilityCommand(
            storageId: $this->storage->id,
            startDate: new \DateTimeImmutable('2025-07-01'),
            endDate: new \DateTimeImmutable('2025-07-15'),
            reason: 'Údržba',
            createdById: $this->user->id,
        );

        $unavailability = ($this->handler)($command);

        $this->assertSame($this->storage, $unavailability->storage);
    }

    private function createOrder(
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        RentalType $rentalType = RentalType::LIMITED,
    ): Order {
        return new Order(
            id: Uuid::v7(),
            user: $this->user,
            storage: $this->storage,
            rentalType: $rentalType,
            paymentFrequency: null,
            startDate: $startDate,
            endDate: $endDate,
            totalPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
    }
}
