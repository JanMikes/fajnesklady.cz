<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\StorageUnavailability;
use App\Entity\User;
use App\Repository\StorageUnavailabilityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class StorageUnavailabilityRepositoryTest extends KernelTestCase
{
    private StorageUnavailabilityRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(StorageUnavailabilityRepository::class);
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

    private function createUnavailability(
        Storage $storage,
        User $createdBy,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        string $reason = 'Test reason',
    ): StorageUnavailability {
        $unavailability = new StorageUnavailability(
            id: Uuid::v7(),
            storage: $storage,
            startDate: $startDate,
            endDate: $endDate,
            reason: $reason,
            createdBy: $createdBy,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($unavailability);

        return $unavailability;
    }

    public function testFindOverlappingWithLimitedPeriod(): void
    {
        $owner = $this->createUser('landlord-una-overlap@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'UNA1');

        // Existing unavailability: Jan 10-20
        $existingUnavailability = $this->createUnavailability(
            $storage,
            $owner,
            new \DateTimeImmutable('2024-01-10'),
            new \DateTimeImmutable('2024-01-20'),
        );
        $this->entityManager->flush();

        // Check overlap: Jan 15-25 (overlaps with Jan 10-20)
        $overlapping = $this->repository->findOverlappingByStorage(
            $storage,
            new \DateTimeImmutable('2024-01-15'),
            new \DateTimeImmutable('2024-01-25'),
        );

        $this->assertCount(1, $overlapping);
        $this->assertEquals($existingUnavailability->id, $overlapping[0]->id);
    }

    public function testFindOverlappingWithUnlimitedPeriod(): void
    {
        $owner = $this->createUser('landlord-una-unlimited@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'UNA2');

        // Existing indefinite unavailability starting Jan 1
        $existingUnavailability = $this->createUnavailability(
            $storage,
            $owner,
            new \DateTimeImmutable('2024-01-01'),
            null,
        );
        $this->entityManager->flush();

        // Check overlap: Feb 1-28 (should overlap with indefinite unavailability)
        $overlapping = $this->repository->findOverlappingByStorage(
            $storage,
            new \DateTimeImmutable('2024-02-01'),
            new \DateTimeImmutable('2024-02-28'),
        );

        $this->assertCount(1, $overlapping);
    }

    public function testFindOverlappingWithUnlimitedRequestedPeriod(): void
    {
        $owner = $this->createUser('landlord-una-unlimited2@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'UNA3');

        // Existing limited unavailability: Feb 1-28
        $existingUnavailability = $this->createUnavailability(
            $storage,
            $owner,
            new \DateTimeImmutable('2024-02-01'),
            new \DateTimeImmutable('2024-02-28'),
        );
        $this->entityManager->flush();

        // Check overlap with indefinite period starting Jan 15
        $overlapping = $this->repository->findOverlappingByStorage(
            $storage,
            new \DateTimeImmutable('2024-01-15'),
            null,
        );

        $this->assertCount(1, $overlapping);
    }

    public function testFindOverlappingNoOverlapForAdjacentPeriods(): void
    {
        $owner = $this->createUser('landlord-una-adjacent@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'UNA4');

        // Existing unavailability: Jan 1-10
        $existingUnavailability = $this->createUnavailability(
            $storage,
            $owner,
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-10'),
        );
        $this->entityManager->flush();

        // Check overlap: Jan 11-20 (adjacent, not overlapping)
        $overlapping = $this->repository->findOverlappingByStorage(
            $storage,
            new \DateTimeImmutable('2024-01-11'),
            new \DateTimeImmutable('2024-01-20'),
        );

        $this->assertCount(0, $overlapping);
    }

    public function testFindActiveByStorageOnDateReturnsActiveRecords(): void
    {
        $owner = $this->createUser('landlord-una-active@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'UNA5');

        // Unavailability active on Jan 15: Jan 10-20
        $activeUnavailability = $this->createUnavailability(
            $storage,
            $owner,
            new \DateTimeImmutable('2024-01-10'),
            new \DateTimeImmutable('2024-01-20'),
        );

        // Unavailability NOT active on Jan 15: Feb 1-28
        $futureUnavailability = $this->createUnavailability(
            $storage,
            $owner,
            new \DateTimeImmutable('2024-02-01'),
            new \DateTimeImmutable('2024-02-28'),
        );

        // Indefinite unavailability starting Jan 1 (active on Jan 15)
        $indefiniteUnavailability = $this->createUnavailability(
            $storage,
            $owner,
            new \DateTimeImmutable('2024-01-01'),
            null,
        );

        $this->entityManager->flush();

        $active = $this->repository->findActiveByStorageOnDate($storage, new \DateTimeImmutable('2024-01-15'));
        $unavailabilityIds = array_map(fn (StorageUnavailability $u) => $u->id->toRfc4122(), $active);

        $this->assertContains($activeUnavailability->id->toRfc4122(), $unavailabilityIds);
        $this->assertContains($indefiniteUnavailability->id->toRfc4122(), $unavailabilityIds);
        $this->assertNotContains($futureUnavailability->id->toRfc4122(), $unavailabilityIds);
    }

    public function testFindByStorageTypeInDateRange(): void
    {
        $owner = $this->createUser('landlord-una-type@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage1 = $this->createStorage($storageType, $place, 'UNA6');
        $storage2 = $this->createStorage($storageType, $place, 'UNA7');

        // Unavailability for storage1: Jan 10-20
        $unavailability1 = $this->createUnavailability(
            $storage1,
            $owner,
            new \DateTimeImmutable('2024-01-10'),
            new \DateTimeImmutable('2024-01-20'),
        );

        // Unavailability for storage2: Jan 15-25
        $unavailability2 = $this->createUnavailability(
            $storage2,
            $owner,
            new \DateTimeImmutable('2024-01-15'),
            new \DateTimeImmutable('2024-01-25'),
        );

        $this->entityManager->flush();

        // Query for range Jan 12-18 (should match both)
        $found = $this->repository->findByStorageTypeInDateRange(
            $storageType,
            new \DateTimeImmutable('2024-01-12'),
            new \DateTimeImmutable('2024-01-18'),
        );

        $this->assertCount(2, $found);
    }

    public function testFindByOwner(): void
    {
        $owner1 = $this->createUser('landlord-una-owner1@test.com');
        $owner2 = $this->createUser('landlord-una-owner2@test.com');
        $place1 = $this->createPlace();
        $place2 = $this->createPlace();
        $storageType1 = $this->createStorageType();
        $storageType2 = $this->createStorageType();
        $storage1 = $this->createStorage($storageType1, $place1, 'UNA8', $owner1);
        $storage2 = $this->createStorage($storageType2, $place2, 'UNA9', $owner2);

        // Unavailability for owner1's storage
        $unavailability1 = $this->createUnavailability(
            $storage1,
            $owner1,
            new \DateTimeImmutable('2024-01-10'),
            new \DateTimeImmutable('2024-01-20'),
        );

        // Unavailability for owner2's storage
        $unavailability2 = $this->createUnavailability(
            $storage2,
            $owner2,
            new \DateTimeImmutable('2024-01-15'),
            new \DateTimeImmutable('2024-01-25'),
        );

        $this->entityManager->flush();

        $owner1Unavailabilities = $this->repository->findByOwner($owner1);
        $owner2Unavailabilities = $this->repository->findByOwner($owner2);

        $this->assertCount(1, $owner1Unavailabilities);
        $this->assertCount(1, $owner2Unavailabilities);
        $this->assertEquals($unavailability1->id, $owner1Unavailabilities[0]->id);
        $this->assertEquals($unavailability2->id, $owner2Unavailabilities[0]->id);
    }
}
