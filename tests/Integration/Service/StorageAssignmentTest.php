<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\StorageUnavailability;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Exception\NoStorageAvailable;
use App\Service\StorageAssignment;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class StorageAssignmentTest extends KernelTestCase
{
    private StorageAssignment $storageAssignment;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->storageAssignment = $container->get(StorageAssignment::class);
        $this->entityManager = $container->get(EntityManagerInterface::class);
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
            width: 100,
            height: 100,
            length: 100,
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

    private function createOrder(
        User $user,
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        RentalType $rentalType = RentalType::LIMITED,
    ): Order {
        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: $rentalType,
            paymentFrequency: RentalType::UNLIMITED === $rentalType ? PaymentFrequency::MONTHLY : null,
            startDate: $startDate,
            endDate: $endDate,
            totalPrice: 50000,
            expiresAt: (new \DateTimeImmutable())->modify('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($order);

        return $order;
    }

    private function createContract(
        Order $order,
        User $user,
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        RentalType $rentalType = RentalType::LIMITED,
    ): Contract {
        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            rentalType: $rentalType,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($contract);

        return $contract;
    }

    private function createUnavailability(
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        User $createdBy,
    ): StorageUnavailability {
        $unavailability = new StorageUnavailability(
            id: Uuid::v7(),
            storage: $storage,
            startDate: $startDate,
            endDate: $endDate,
            reason: 'Test reason',
            createdBy: $createdBy,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($unavailability);

        return $unavailability;
    }

    public function testAssignsFirstAvailableStorage(): void
    {
        $owner = $this->createUser('owner@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage1 = $this->createStorage($storageType, 'A1');
        $storage2 = $this->createStorage($storageType, 'A2');
        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+1 day');
        $endDate = new \DateTimeImmutable('+30 days');

        $assigned = $this->storageAssignment->assignStorage($storageType, $startDate, $endDate);

        $this->assertTrue($assigned->id->equals($storage1->id) || $assigned->id->equals($storage2->id));
    }

    public function testNeverAssignsStorageWithOverlappingReservation(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage1 = $this->createStorage($storageType, 'A1');
        $storage2 = $this->createStorage($storageType, 'A2');

        // Create order for storage1 that overlaps with requested period
        $order = $this->createOrder(
            $tenant,
            $storage1,
            new \DateTimeImmutable('+5 days'),
            new \DateTimeImmutable('+35 days'),
        );
        $order->reserve(new \DateTimeImmutable());

        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+10 days');
        $endDate = new \DateTimeImmutable('+40 days');

        $assigned = $this->storageAssignment->assignStorage($storageType, $startDate, $endDate);

        // Should get storage2, not storage1
        $this->assertTrue($assigned->id->equals($storage2->id));
    }

    public function testNeverAssignsStorageWithOverlappingContract(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage1 = $this->createStorage($storageType, 'A1');
        $storage2 = $this->createStorage($storageType, 'A2');

        // Create contract for storage1
        $order = $this->createOrder(
            $tenant,
            $storage1,
            new \DateTimeImmutable('+5 days'),
            new \DateTimeImmutable('+35 days'),
        );
        $this->createContract(
            $order,
            $tenant,
            $storage1,
            new \DateTimeImmutable('+5 days'),
            new \DateTimeImmutable('+35 days'),
        );

        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+10 days');
        $endDate = new \DateTimeImmutable('+40 days');

        $assigned = $this->storageAssignment->assignStorage($storageType, $startDate, $endDate);

        // Should get storage2, not storage1
        $this->assertTrue($assigned->id->equals($storage2->id));
    }

    public function testNeverAssignsManuallyBlockedStorage(): void
    {
        $owner = $this->createUser('owner@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage1 = $this->createStorage($storageType, 'A1');
        $storage2 = $this->createStorage($storageType, 'A2');

        // Block storage1
        $this->createUnavailability(
            $storage1,
            new \DateTimeImmutable('+5 days'),
            new \DateTimeImmutable('+35 days'),
            $owner,
        );

        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+10 days');
        $endDate = new \DateTimeImmutable('+40 days');

        $assigned = $this->storageAssignment->assignStorage($storageType, $startDate, $endDate);

        // Should get storage2, not storage1
        $this->assertTrue($assigned->id->equals($storage2->id));
    }

    public function testHandlesUnlimitedRentalsWithNullEndDate(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage1 = $this->createStorage($storageType, 'A1');
        $storage2 = $this->createStorage($storageType, 'A2');

        // Create unlimited contract for storage1
        $order = $this->createOrder(
            $tenant,
            $storage1,
            new \DateTimeImmutable('+5 days'),
            null,
            RentalType::UNLIMITED,
        );
        $this->createContract(
            $order,
            $tenant,
            $storage1,
            new \DateTimeImmutable('+5 days'),
            null,
            RentalType::UNLIMITED,
        );

        $this->entityManager->flush();

        // Try to get a storage for unlimited rental
        $startDate = new \DateTimeImmutable('+10 days');

        $assigned = $this->storageAssignment->assignStorage($storageType, $startDate, null);

        // Should get storage2, not storage1
        $this->assertTrue($assigned->id->equals($storage2->id));
    }

    public function testPrefersSameStorageForUserExtension(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage1 = $this->createStorage($storageType, 'A1');
        $storage2 = $this->createStorage($storageType, 'A2');

        // Create active contract for storage1 ending on day 30
        $order = $this->createOrder(
            $tenant,
            $storage1,
            new \DateTimeImmutable('-10 days'),
            new \DateTimeImmutable('+20 days'),
        );
        $this->createContract(
            $order,
            $tenant,
            $storage1,
            new \DateTimeImmutable('-10 days'),
            new \DateTimeImmutable('+20 days'),
        );

        $this->entityManager->flush();

        // Extension starts where previous ends
        $startDate = new \DateTimeImmutable('+21 days');
        $endDate = new \DateTimeImmutable('+51 days');

        $assigned = $this->storageAssignment->assignStorage($storageType, $startDate, $endDate, $tenant);

        // Should get storage1 (same storage for extension)
        $this->assertTrue($assigned->id->equals($storage1->id));
    }

    public function testFallsBackToDifferentStorageWhenSameUnavailable(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $otherTenant = $this->createUser('other@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage1 = $this->createStorage($storageType, 'A1');
        $storage2 = $this->createStorage($storageType, 'A2');

        // Create active contract for storage1 for tenant
        $order1 = $this->createOrder(
            $tenant,
            $storage1,
            new \DateTimeImmutable('-10 days'),
            new \DateTimeImmutable('+20 days'),
        );
        $this->createContract(
            $order1,
            $tenant,
            $storage1,
            new \DateTimeImmutable('-10 days'),
            new \DateTimeImmutable('+20 days'),
        );

        // Block storage1 by another tenant for extension period
        $order2 = $this->createOrder(
            $otherTenant,
            $storage1,
            new \DateTimeImmutable('+21 days'),
            new \DateTimeImmutable('+51 days'),
        );
        $order2->reserve(new \DateTimeImmutable());

        $this->entityManager->flush();

        // Tenant wants to extend but storage1 is blocked
        $startDate = new \DateTimeImmutable('+21 days');
        $endDate = new \DateTimeImmutable('+51 days');

        $assigned = $this->storageAssignment->assignStorage($storageType, $startDate, $endDate, $tenant);

        // Should get storage2 (fallback)
        $this->assertTrue($assigned->id->equals($storage2->id));
    }

    public function testThrowsExceptionWhenNoStorageAvailable(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage1 = $this->createStorage($storageType, 'A1');

        // Block the only storage
        $order = $this->createOrder(
            $tenant,
            $storage1,
            new \DateTimeImmutable('+5 days'),
            new \DateTimeImmutable('+35 days'),
        );
        $order->reserve(new \DateTimeImmutable());

        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+10 days');
        $endDate = new \DateTimeImmutable('+40 days');

        $this->expectException(NoStorageAvailable::class);
        $this->storageAssignment->assignStorage($storageType, $startDate, $endDate);
    }

    public function testHasAvailableStorageReturnsTrue(): void
    {
        $owner = $this->createUser('owner@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $this->createStorage($storageType, 'A1');
        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+1 day');
        $endDate = new \DateTimeImmutable('+30 days');

        $this->assertTrue($this->storageAssignment->hasAvailableStorage($storageType, $startDate, $endDate));
    }

    public function testHasAvailableStorageReturnsFalseWhenBlocked(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, 'A1');

        $order = $this->createOrder(
            $tenant,
            $storage,
            new \DateTimeImmutable('+5 days'),
            new \DateTimeImmutable('+35 days'),
        );
        $order->reserve(new \DateTimeImmutable());

        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+10 days');
        $endDate = new \DateTimeImmutable('+40 days');

        $this->assertFalse($this->storageAssignment->hasAvailableStorage($storageType, $startDate, $endDate));
    }

    public function testCountAvailableStorages(): void
    {
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage1 = $this->createStorage($storageType, 'A1');
        $storage2 = $this->createStorage($storageType, 'A2');
        $storage3 = $this->createStorage($storageType, 'A3');

        // Block one storage
        $order = $this->createOrder(
            $tenant,
            $storage1,
            new \DateTimeImmutable('+5 days'),
            new \DateTimeImmutable('+35 days'),
        );
        $order->reserve(new \DateTimeImmutable());

        $this->entityManager->flush();

        $startDate = new \DateTimeImmutable('+10 days');
        $endDate = new \DateTimeImmutable('+40 days');

        $count = $this->storageAssignment->countAvailableStorages($storageType, $startDate, $endDate);

        $this->assertSame(2, $count);
    }

    public function testNoOverlapWhenPeriodsAreAdjacent(): void
    {
        // Contract expires on day, new rental starts on next day - no overlap
        $owner = $this->createUser('owner@test.com');
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, 'A1');

        // Contract ends on day 20
        $order = $this->createOrder(
            $tenant,
            $storage,
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+20 days'),
        );
        $this->createContract(
            $order,
            $tenant,
            $storage,
            new \DateTimeImmutable('+1 day'),
            new \DateTimeImmutable('+20 days'),
        );

        $this->entityManager->flush();

        // New rental starts on day 21 (no overlap)
        $startDate = new \DateTimeImmutable('+21 days');
        $endDate = new \DateTimeImmutable('+51 days');

        $assigned = $this->storageAssignment->assignStorage($storageType, $startDate, $endDate);

        // Should get the same storage since there's no overlap
        $this->assertTrue($assigned->id->equals($storage->id));
    }
}
