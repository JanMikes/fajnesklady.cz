<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Exception\ContractNotFound;
use App\Repository\ContractRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class ContractRepositoryTest extends KernelTestCase
{
    private ContractRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(ContractRepository::class);
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
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
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

    private function createOrder(User $user, Storage $storage, \DateTimeImmutable $startDate, ?\DateTimeImmutable $endDate): Order
    {
        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: null === $endDate ? RentalType::UNLIMITED : RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            totalPrice: 10000,
            expiresAt: new \DateTimeImmutable('+7 days'),
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
    ): Contract {
        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            rentalType: null === $endDate ? RentalType::UNLIMITED : RentalType::LIMITED,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($contract);

        return $contract;
    }

    public function testGetThrowsForNonexistent(): void
    {
        $nonexistentId = Uuid::v7();

        $this->expectException(ContractNotFound::class);

        $this->repository->get($nonexistentId);
    }

    public function testFindOverlappingDetectsOverlappingLimitedPeriods(): void
    {
        $owner = $this->createUser('landlord-c-overlap1@test.com');
        $tenant = $this->createUser('tenant-c-overlap1@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, 'COL1');

        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('2024-01-10'), new \DateTimeImmutable('2024-01-20'));

        // Existing contract: Jan 10-20
        $existingContract = $this->createContract(
            $order,
            $tenant,
            $storage,
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
        $this->assertEquals($existingContract->id, $overlapping[0]->id);
    }

    public function testFindOverlappingHandlesIndefinitePeriod(): void
    {
        $owner = $this->createUser('landlord-c-unlimited@test.com');
        $tenant = $this->createUser('tenant-c-unlimited@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, 'CUNL');

        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('2024-01-01'), null);

        // Existing unlimited contract starting Jan 1
        $existingContract = $this->createContract(
            $order,
            $tenant,
            $storage,
            new \DateTimeImmutable('2024-01-01'),
            null,
        );
        $this->entityManager->flush();

        // Check overlap: Feb 1-28 (should overlap with unlimited contract)
        $overlapping = $this->repository->findOverlappingByStorage(
            $storage,
            new \DateTimeImmutable('2024-02-01'),
            new \DateTimeImmutable('2024-02-28'),
        );

        $this->assertCount(1, $overlapping);
    }

    public function testFindOverlappingExcludesTerminatedContracts(): void
    {
        $owner = $this->createUser('landlord-c-term@test.com');
        $tenant = $this->createUser('tenant-c-term@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, 'CTRM');

        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('2024-01-10'), new \DateTimeImmutable('2024-01-20'));

        // Terminated contract: Jan 10-20
        $terminatedContract = $this->createContract(
            $order,
            $tenant,
            $storage,
            new \DateTimeImmutable('2024-01-10'),
            new \DateTimeImmutable('2024-01-20'),
        );
        $terminatedContract->terminate(new \DateTimeImmutable());
        $this->entityManager->flush();

        // Check overlap: Jan 15-25 (terminated contract should be ignored)
        $overlapping = $this->repository->findOverlappingByStorage(
            $storage,
            new \DateTimeImmutable('2024-01-15'),
            new \DateTimeImmutable('2024-01-25'),
        );

        $this->assertCount(0, $overlapping);
    }

    public function testFindExpiringWithinDaysReturnsCorrectContracts(): void
    {
        $owner = $this->createUser('landlord-c-exp@test.com');
        $tenant = $this->createUser('tenant-c-exp@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage1 = $this->createStorage($storageType, 'CEXP1');
        $storage2 = $this->createStorage($storageType, 'CEXP2');
        $storage3 = $this->createStorage($storageType, 'CEXP3');

        $now = new \DateTimeImmutable('2024-06-15');

        // Contract expiring in 5 days (should be found for 7-day window)
        $order1 = $this->createOrder($tenant, $storage1, new \DateTimeImmutable('2024-05-01'), new \DateTimeImmutable('2024-06-20'));
        $expiringContract = $this->createContract(
            $order1,
            $tenant,
            $storage1,
            new \DateTimeImmutable('2024-05-01'),
            new \DateTimeImmutable('2024-06-20'),
        );

        // Contract expiring in 30 days (should NOT be found for 7-day window)
        $order2 = $this->createOrder($tenant, $storage2, new \DateTimeImmutable('2024-05-01'), new \DateTimeImmutable('2024-07-15'));
        $farContract = $this->createContract(
            $order2,
            $tenant,
            $storage2,
            new \DateTimeImmutable('2024-05-01'),
            new \DateTimeImmutable('2024-07-15'),
        );

        // Unlimited contract (should NOT be found)
        $order3 = $this->createOrder($tenant, $storage3, new \DateTimeImmutable('2024-05-01'), null);
        $unlimitedContract = $this->createContract(
            $order3,
            $tenant,
            $storage3,
            new \DateTimeImmutable('2024-05-01'),
            null,
        );

        $this->entityManager->flush();

        $expiring = $this->repository->findExpiringWithinDays(7, $now);
        $contractIds = array_map(fn (Contract $c) => $c->id->toRfc4122(), $expiring);

        $this->assertContains($expiringContract->id->toRfc4122(), $contractIds);
        $this->assertNotContains($farContract->id->toRfc4122(), $contractIds);
        $this->assertNotContains($unlimitedContract->id->toRfc4122(), $contractIds);
    }

    public function testFindActiveByUserExcludesTerminated(): void
    {
        $owner = $this->createUser('landlord-c-active@test.com');
        $tenant = $this->createUser('tenant-c-active@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage1 = $this->createStorage($storageType, 'CACT1');
        $storage2 = $this->createStorage($storageType, 'CACT2');

        $now = new \DateTimeImmutable('2024-06-15');

        // Active contract
        $order1 = $this->createOrder($tenant, $storage1, new \DateTimeImmutable('2024-06-01'), new \DateTimeImmutable('2024-07-01'));
        $activeContract = $this->createContract(
            $order1,
            $tenant,
            $storage1,
            new \DateTimeImmutable('2024-06-01'),
            new \DateTimeImmutable('2024-07-01'),
        );

        // Terminated contract
        $order2 = $this->createOrder($tenant, $storage2, new \DateTimeImmutable('2024-05-01'), new \DateTimeImmutable('2024-06-30'));
        $terminatedContract = $this->createContract(
            $order2,
            $tenant,
            $storage2,
            new \DateTimeImmutable('2024-05-01'),
            new \DateTimeImmutable('2024-06-30'),
        );
        $terminatedContract->terminate(new \DateTimeImmutable());

        $this->entityManager->flush();

        $activeContracts = $this->repository->findActiveByUser($tenant, $now);
        $contractIds = array_map(fn (Contract $c) => $c->id->toRfc4122(), $activeContracts);

        $this->assertContains($activeContract->id->toRfc4122(), $contractIds);
        $this->assertNotContains($terminatedContract->id->toRfc4122(), $contractIds);
    }

    public function testFindActiveByStorageFiltersCorrectly(): void
    {
        $owner = $this->createUser('landlord-c-storage@test.com');
        $tenant = $this->createUser('tenant-c-storage@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, 'CSTR');

        $now = new \DateTimeImmutable('2024-06-15');

        // Active contract for this storage
        $order1 = $this->createOrder($tenant, $storage, new \DateTimeImmutable('2024-06-01'), new \DateTimeImmutable('2024-07-01'));
        $activeContract = $this->createContract(
            $order1,
            $tenant,
            $storage,
            new \DateTimeImmutable('2024-06-01'),
            new \DateTimeImmutable('2024-07-01'),
        );

        $this->entityManager->flush();

        $activeContracts = $this->repository->findActiveByStorage($storage, $now);

        $this->assertCount(1, $activeContracts);
        $this->assertEquals($activeContract->id, $activeContracts[0]->id);
    }
}
