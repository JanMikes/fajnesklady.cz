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
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\StorageStatus;
use App\Service\StorageAvailabilityChecker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Guards the single source of truth for booking availability. The map, the
 * select guards, and order-acceptance enforcement must never disagree — so the
 * bulk {@see StorageAvailabilityChecker::availabilityForStorages()} and the
 * per-storage {@see StorageAvailabilityChecker::isAvailable()} are asserted to
 * agree across every scenario, and the derived status precedence is pinned.
 */
final class StorageAvailabilityCheckerTest extends KernelTestCase
{
    private StorageAvailabilityChecker $checker;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->checker = $container->get(StorageAvailabilityChecker::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testFreeStorageIsAvailableAndSingleAgreesWithBulk(): void
    {
        [$place, $storageType] = $this->createPlaceAndType();
        $storage = $this->createStorage($storageType, $place, 'A1');
        $this->entityManager->flush();

        $start = new \DateTimeImmutable('+10 days');
        $end = new \DateTimeImmutable('+40 days');

        $result = $this->checker->availabilityForStorages([$storage], $start, $end);

        self::assertTrue($result[$storage->id->toRfc4122()]->isAvailable);
        self::assertSame(StorageStatus::AVAILABLE, $result[$storage->id->toRfc4122()]->derivedStatus);
        $this->assertSingleAgreesWithBulk($storage, $start, $end);
    }

    public function testOverlappingOrderMakesStorageUnavailableWithReservedStatus(): void
    {
        $tenant = $this->createUser('tenant-order@test.com');
        [$place, $storageType] = $this->createPlaceAndType();
        $storage = $this->createStorage($storageType, $place, 'A1');

        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('+5 days'), new \DateTimeImmutable('+35 days'));
        $order->reserve(new \DateTimeImmutable());
        $this->entityManager->flush();

        $start = new \DateTimeImmutable('+10 days');
        $end = new \DateTimeImmutable('+40 days');

        $availability = $this->checker->availabilityForStorages([$storage], $start, $end)[$storage->id->toRfc4122()];

        self::assertFalse($availability->isAvailable);
        self::assertSame(StorageStatus::RESERVED, $availability->derivedStatus);
        $this->assertSingleAgreesWithBulk($storage, $start, $end);
    }

    public function testOverlappingContractMakesStorageUnavailableWithOccupiedStatus(): void
    {
        $tenant = $this->createUser('tenant-contract@test.com');
        [$place, $storageType] = $this->createPlaceAndType();
        $storage = $this->createStorage($storageType, $place, 'A1');

        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('+5 days'), new \DateTimeImmutable('+35 days'));
        $this->createContract($order, $tenant, $storage, new \DateTimeImmutable('+5 days'), new \DateTimeImmutable('+35 days'));
        $this->entityManager->flush();

        $start = new \DateTimeImmutable('+10 days');
        $end = new \DateTimeImmutable('+40 days');

        $availability = $this->checker->availabilityForStorages([$storage], $start, $end)[$storage->id->toRfc4122()];

        self::assertFalse($availability->isAvailable);
        self::assertSame(StorageStatus::OCCUPIED, $availability->derivedStatus);
        $this->assertSingleAgreesWithBulk($storage, $start, $end);
    }

    public function testManualBlockRecordMakesStorageUnavailable(): void
    {
        $owner = $this->createUser('owner-block@test.com');
        [$place, $storageType] = $this->createPlaceAndType();
        $storage = $this->createStorage($storageType, $place, 'A1');

        $this->createUnavailability($storage, new \DateTimeImmutable('+5 days'), new \DateTimeImmutable('+35 days'), $owner);
        $this->entityManager->flush();

        $start = new \DateTimeImmutable('+10 days');
        $end = new \DateTimeImmutable('+40 days');

        $availability = $this->checker->availabilityForStorages([$storage], $start, $end)[$storage->id->toRfc4122()];

        self::assertFalse($availability->isAvailable);
        self::assertSame(StorageStatus::MANUALLY_UNAVAILABLE, $availability->derivedStatus);
        $this->assertSingleAgreesWithBulk($storage, $start, $end);
    }

    public function testManuallyUnavailableStatusBlocksOnEveryDate(): void
    {
        [$place, $storageType] = $this->createPlaceAndType();
        $storage = $this->createStorage($storageType, $place, 'A1');
        $storage->markUnavailable(new \DateTimeImmutable());
        $this->entityManager->flush();

        $start = new \DateTimeImmutable('+10 days');
        $end = new \DateTimeImmutable('+40 days');

        $availability = $this->checker->availabilityForStorages([$storage], $start, $end)[$storage->id->toRfc4122()];

        self::assertFalse($availability->isAvailable);
        self::assertSame(StorageStatus::MANUALLY_UNAVAILABLE, $availability->derivedStatus);
        $this->assertSingleAgreesWithBulk($storage, $start, $end);
    }

    public function testAutoRecurringOrderBlocksAnyFutureWindowBeyondItsEndDate(): void
    {
        $tenant = $this->createUser('tenant-auto-order@test.com');
        [$place, $storageType] = $this->createPlaceAndType();
        $storage = $this->createStorage($storageType, $place, 'A1');

        // Card-recurring order: blocks the storage open-endedly while alive (spec 076).
        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('+5 days'), new \DateTimeImmutable('+35 days'), BillingMode::AUTO_RECURRING);
        $order->reserve(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Requested window lies entirely AFTER the order's end date.
        $start = new \DateTimeImmutable('+40 days');
        $end = new \DateTimeImmutable('+70 days');

        $availability = $this->checker->availabilityForStorages([$storage], $start, $end)[$storage->id->toRfc4122()];

        self::assertFalse($availability->isAvailable);
        self::assertSame(StorageStatus::RESERVED, $availability->derivedStatus);
        $this->assertSingleAgreesWithBulk($storage, $start, $end);
    }

    public function testLiveTokenRecurringContractBlocksAnyFutureWindow(): void
    {
        $tenant = $this->createUser('tenant-guarantee@test.com');
        [$place, $storageType] = $this->createPlaceAndType();
        $storage = $this->createStorage($storageType, $place, 'A1');

        // The order itself is ONE_TIME so only the CONTRACT's availability
        // guarantee (AUTO_RECURRING + live token + no pending termination) can
        // block windows past the contract's end date.
        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('+5 days'), new \DateTimeImmutable('+35 days'));
        $contract = $this->createContract($order, $tenant, $storage, new \DateTimeImmutable('+5 days'), new \DateTimeImmutable('+35 days'));
        $contract->setRecurringPayment('gopay-parent-guarantee', new \DateTimeImmutable('+35 days'), new \DateTimeImmutable('+35 days'));
        $this->entityManager->flush();

        // Open-ended request starting AFTER the contract's end date.
        $start = new \DateTimeImmutable('+40 days');

        $availability = $this->checker->availabilityForStorages([$storage], $start, null)[$storage->id->toRfc4122()];

        self::assertFalse($availability->isAvailable);
        self::assertSame(StorageStatus::OCCUPIED, $availability->derivedStatus);
        $this->assertSingleAgreesWithBulk($storage, $start, null);
    }

    public function testDerivedStatusPrecedenceBlockBeatsContractBeatsOrder(): void
    {
        $tenant = $this->createUser('tenant-precedence@test.com');
        $owner = $this->createUser('owner-precedence@test.com');
        [$place, $storageType] = $this->createPlaceAndType();
        $storage = $this->createStorage($storageType, $place, 'A1');

        $start = new \DateTimeImmutable('+10 days');
        $end = new \DateTimeImmutable('+40 days');

        // Overlapping order AND contract → contract (OCCUPIED) wins over order (RESERVED).
        $order = $this->createOrder($tenant, $storage, $start, $end);
        $order->reserve(new \DateTimeImmutable());
        $this->createContract($order, $tenant, $storage, $start, $end);
        $this->entityManager->flush();

        self::assertSame(
            StorageStatus::OCCUPIED,
            $this->checker->availabilityForStorages([$storage], $start, $end)[$storage->id->toRfc4122()]->derivedStatus,
        );

        // Add a manual block → block (MANUALLY_UNAVAILABLE) now wins over the contract.
        $this->createUnavailability($storage, $start, $end, $owner);
        $this->entityManager->flush();

        self::assertSame(
            StorageStatus::MANUALLY_UNAVAILABLE,
            $this->checker->availabilityForStorages([$storage], $start, $end)[$storage->id->toRfc4122()]->derivedStatus,
        );
    }

    public function testStaleOccupiedStatusDoesNotBlockAFreeWindow(): void
    {
        // The reported bug: status drifted to OCCUPIED but there is no overlapping
        // record for the requested window → must be available.
        [$place, $storageType] = $this->createPlaceAndType();
        $storage = $this->createStorage($storageType, $place, 'A1');
        $storage->occupy(new \DateTimeImmutable());
        $this->entityManager->flush();

        $start = new \DateTimeImmutable('+10 days');
        $end = new \DateTimeImmutable('+40 days');

        $availability = $this->checker->availabilityForStorages([$storage], $start, $end)[$storage->id->toRfc4122()];

        self::assertTrue($availability->isAvailable, 'Stale OCCUPIED status must not block a genuinely free window.');
        self::assertSame(StorageStatus::AVAILABLE, $availability->derivedStatus);
        $this->assertSingleAgreesWithBulk($storage, $start, $end);
    }

    public function testStaleAvailableStatusStillBlockedByOverlappingContract(): void
    {
        // The mirror bug: status says AVAILABLE but a contract overlaps the
        // requested window → must be unavailable.
        $tenant = $this->createUser('tenant-stale-avail@test.com');
        [$place, $storageType] = $this->createPlaceAndType();
        $storage = $this->createStorage($storageType, $place, 'A1');
        self::assertSame(StorageStatus::AVAILABLE, $storage->status);

        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('+5 days'), new \DateTimeImmutable('+35 days'));
        $this->createContract($order, $tenant, $storage, new \DateTimeImmutable('+5 days'), new \DateTimeImmutable('+35 days'));
        $this->entityManager->flush();

        $start = new \DateTimeImmutable('+10 days');
        $end = new \DateTimeImmutable('+40 days');

        self::assertFalse($this->checker->availabilityForStorages([$storage], $start, $end)[$storage->id->toRfc4122()]->isAvailable);
        $this->assertSingleAgreesWithBulk($storage, $start, $end);
    }

    public function testAdjacentContractDoesNotBlock(): void
    {
        $tenant = $this->createUser('tenant-adjacent@test.com');
        [$place, $storageType] = $this->createPlaceAndType();
        $storage = $this->createStorage($storageType, $place, 'A1');

        // Contract ends on +20 days; requested window starts +21 days — no overlap.
        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+20 days'));
        $this->createContract($order, $tenant, $storage, new \DateTimeImmutable('+1 day'), new \DateTimeImmutable('+20 days'));
        $this->entityManager->flush();

        $start = new \DateTimeImmutable('+21 days');
        $end = new \DateTimeImmutable('+51 days');

        self::assertTrue($this->checker->availabilityForStorages([$storage], $start, $end)[$storage->id->toRfc4122()]->isAvailable);
        $this->assertSingleAgreesWithBulk($storage, $start, $end);
    }

    public function testBulkKeepsStoragesIndependentAndKeyedById(): void
    {
        $tenant = $this->createUser('tenant-bulk@test.com');
        [$place, $storageType] = $this->createPlaceAndType();
        $free = $this->createStorage($storageType, $place, 'A1');
        $taken = $this->createStorage($storageType, $place, 'A2');

        $start = new \DateTimeImmutable('+10 days');
        $end = new \DateTimeImmutable('+40 days');

        $order = $this->createOrder($tenant, $taken, $start, $end);
        $order->reserve(new \DateTimeImmutable());
        $this->entityManager->flush();

        $result = $this->checker->availabilityForStorages([$free, $taken], $start, $end);

        self::assertCount(2, $result);
        self::assertTrue($result[$free->id->toRfc4122()]->isAvailable);
        self::assertFalse($result[$taken->id->toRfc4122()]->isAvailable);
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        self::assertSame([], $this->checker->availabilityForStorages([], new \DateTimeImmutable('+10 days'), new \DateTimeImmutable('+40 days')));
    }

    private function assertSingleAgreesWithBulk(Storage $storage, \DateTimeImmutable $start, ?\DateTimeImmutable $end): void
    {
        $single = $this->checker->isAvailable($storage, $start, $end);
        $bulk = $this->checker->availabilityForStorages([$storage], $start, $end)[$storage->id->toRfc4122()]->isAvailable;

        self::assertSame($single, $bulk, 'isAvailable() and availabilityForStorages() must agree.');
    }

    /**
     * @return array{Place, StorageType}
     */
    private function createPlaceAndType(): array
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

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Test Type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($storageType);

        return [$place, $storageType];
    }

    private function createStorage(StorageType $storageType, Place $place, string $number): Storage
    {
        $storage = new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($storage);

        return $storage;
    }

    private function createUser(string $email): User
    {
        $user = new User(Uuid::v7(), $email, 'password', 'Test', 'User', new \DateTimeImmutable());
        $this->entityManager->persist($user);

        return $user;
    }

    private function createOrder(
        User $user,
        Storage $storage,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        BillingMode $billingMode = BillingMode::ONE_TIME,
    ): Order {
        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: 50000,
            expiresAt: (new \DateTimeImmutable())->modify('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
        // ONE_TIME (bank-transfer style) blocks only [startDate, endDate];
        // AUTO_RECURRING blocks the storage open-endedly (spec 076 guarantee).
        $order->setBillingMode($billingMode);
        $this->entityManager->persist($order);

        return $order;
    }

    private function createContract(
        Order $order,
        User $user,
        Storage $storage,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
    ): Contract {
        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
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
}
