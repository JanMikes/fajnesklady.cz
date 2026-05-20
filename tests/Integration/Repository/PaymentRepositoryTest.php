<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Repository\PaymentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class PaymentRepositoryTest extends KernelTestCase
{
    private PaymentRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(PaymentRepository::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testSumPaidByContractAggregatesAllPayments(): void
    {
        $tenant = $this->createUser('tenant-sum-1@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, $place, 'PSUM1');
        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('2025-06-15'), new \DateTimeImmutable('2025-12-15'));
        $contract = $this->createContract($order, $tenant, $storage, $order->startDate, $order->endDate);

        $this->createPayment($order, $contract, $storage, 500_000, new \DateTimeImmutable('2025-06-15'));
        $this->createPayment(null, $contract, $storage, 500_000, new \DateTimeImmutable('2025-07-15'));
        $this->createPayment(null, $contract, $storage, 500_000, new \DateTimeImmutable('2025-08-15'));
        $this->entityManager->flush();

        $this->assertSame(1_500_000, $this->repository->sumPaidByContract($contract));
    }

    public function testSumPaidByContractReturnsZeroWhenNoPayments(): void
    {
        $tenant = $this->createUser('tenant-sum-2@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, $place, 'PSUM2');
        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('2025-06-15'), new \DateTimeImmutable('2025-12-15'));
        $contract = $this->createContract($order, $tenant, $storage, $order->startDate, $order->endDate);

        $this->entityManager->flush();

        $this->assertSame(0, $this->repository->sumPaidByContract($contract));
    }

    public function testSumPaidByOrderUsedBeforeContractExists(): void
    {
        $tenant = $this->createUser('tenant-sum-3@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, $place, 'PSUM3');
        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('2025-06-15'), null);

        // First payment recorded; contract not yet created.
        $this->createPayment($order, null, $storage, 500_000, new \DateTimeImmutable('2025-06-15'));
        $this->entityManager->flush();

        $this->assertSame(500_000, $this->repository->sumPaidByOrder($order));
    }

    public function testSumPaidByOrderReturnsZeroWhenNoPayments(): void
    {
        $tenant = $this->createUser('tenant-sum-4@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, $place, 'PSUM4');
        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable('2025-06-15'), null);
        $this->entityManager->flush();

        $this->assertSame(0, $this->repository->sumPaidByOrder($order));
    }

    public function testSumAtPlaceAndPeriodScopedByPlaceAndOwner(): void
    {
        $tenant = $this->createUser('tenant-place-period@test.com');
        $landlordA = $this->createUser('landlord-place-period-A@test.com');
        $landlordB = $this->createUser('landlord-place-period-B@test.com');
        $placeA = $this->createPlace();
        $placeB = $this->createPlace();
        $stA = $this->createStorageType($placeA);
        $stB = $this->createStorageType($placeB);
        $storageA1 = $this->createStorage($stA, $placeA, 'PA1', $landlordA);
        $storageA2 = $this->createStorage($stA, $placeA, 'PA2', $landlordB);
        $storageB1 = $this->createStorage($stB, $placeB, 'PB1', $landlordA);

        $orderA1 = $this->createOrder($tenant, $storageA1, new \DateTimeImmutable('2025-05-01'), new \DateTimeImmutable('2025-12-01'));
        $contractA1 = $this->createContract($orderA1, $tenant, $storageA1, $orderA1->startDate, $orderA1->endDate);
        $orderA2 = $this->createOrder($tenant, $storageA2, new \DateTimeImmutable('2025-05-01'), new \DateTimeImmutable('2025-12-01'));
        $contractA2 = $this->createContract($orderA2, $tenant, $storageA2, $orderA2->startDate, $orderA2->endDate);
        $orderB1 = $this->createOrder($tenant, $storageB1, new \DateTimeImmutable('2025-05-01'), new \DateTimeImmutable('2025-12-01'));
        $contractB1 = $this->createContract($orderB1, $tenant, $storageB1, $orderB1->startDate, $orderB1->endDate);

        // May 2025 — included in May query
        $this->createPayment($orderA1, $contractA1, $storageA1, 100_000, new \DateTimeImmutable('2025-05-10'));
        $this->createPayment($orderA2, $contractA2, $storageA2, 200_000, new \DateTimeImmutable('2025-05-15'));
        // Different place, May 2025
        $this->createPayment($orderB1, $contractB1, $storageB1, 50_000, new \DateTimeImmutable('2025-05-20'));
        // Different month — excluded
        $this->createPayment(null, $contractA1, $storageA1, 999_000, new \DateTimeImmutable('2025-04-10'));
        $this->entityManager->flush();

        $this->assertSame(300_000, $this->repository->sumAtPlaceAndPeriod($placeA, 2025, 5, null));
        $this->assertSame(100_000, $this->repository->sumAtPlaceAndPeriod($placeA, 2025, 5, $landlordA));
        $this->assertSame(50_000, $this->repository->sumAtPlaceAndPeriod($placeB, 2025, 5, null));
    }

    public function testSumAtPlaceForRangeIsHalfOpenAndOwnerScoped(): void
    {
        $tenant = $this->createUser('tenant-range@test.com');
        $landlordA = $this->createUser('landlord-range-a@test.com');
        $landlordB = $this->createUser('landlord-range-b@test.com');
        $place = $this->createPlace();
        $st = $this->createStorageType($place);
        $storageA = $this->createStorage($st, $place, 'RNG1', $landlordA);
        $storageB = $this->createStorage($st, $place, 'RNG2', $landlordB);

        $orderA = $this->createOrder($tenant, $storageA, new \DateTimeImmutable('2025-05-01'), new \DateTimeImmutable('2025-12-01'));
        $contractA = $this->createContract($orderA, $tenant, $storageA, $orderA->startDate, $orderA->endDate);
        $orderB = $this->createOrder($tenant, $storageB, new \DateTimeImmutable('2025-05-01'), new \DateTimeImmutable('2025-12-01'));
        $contractB = $this->createContract($orderB, $tenant, $storageB, $orderB->startDate, $orderB->endDate);

        $this->createPayment($orderA, $contractA, $storageA, 100_000, new \DateTimeImmutable('2025-06-01'));
        $this->createPayment(null, $contractA, $storageA, 200_000, new \DateTimeImmutable('2025-06-15'));
        $this->createPayment(null, $contractB, $storageB, 50_000, new \DateTimeImmutable('2025-06-30'));
        // Excluded — falls on the exclusive upper bound.
        $this->createPayment(null, $contractA, $storageA, 999_000, new \DateTimeImmutable('2025-07-01'));
        $this->entityManager->flush();

        $from = new \DateTimeImmutable('2025-06-01 00:00:00');
        $to = new \DateTimeImmutable('2025-07-01 00:00:00');

        // Admin: both owners.
        $this->assertSame(350_000, $this->repository->sumAtPlaceForRange($place, $from, $to, null));
        // Owner scope: only landlordA's payments.
        $this->assertSame(300_000, $this->repository->sumAtPlaceForRange($place, $from, $to, $landlordA));
        // Empty range.
        $this->assertSame(0, $this->repository->sumAtPlaceForRange(
            $place,
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-02-01'),
            null,
        ));
    }

    public function testGetMonthlyRevenueAtPlaceGroupsByMonth(): void
    {
        $tenant = $this->createUser('tenant-monthly-place@test.com');
        $landlord = $this->createUser('landlord-monthly-place@test.com');
        $place = $this->createPlace();
        $st = $this->createStorageType($place);
        $storage = $this->createStorage($st, $place, 'MR1', $landlord);

        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $order = $this->createOrder($tenant, $storage, $now->modify('-90 days'), $now->modify('+90 days'));
        $contract = $this->createContract($order, $tenant, $storage, $order->startDate, $order->endDate);

        $this->createPayment($order, $contract, $storage, 100_000, new \DateTimeImmutable('2025-04-10'));
        $this->createPayment(null, $contract, $storage, 100_000, new \DateTimeImmutable('2025-05-10'));
        $this->createPayment(null, $contract, $storage, 200_000, new \DateTimeImmutable('2025-05-20'));
        $this->createPayment(null, $contract, $storage, 50_000, new \DateTimeImmutable('2025-06-05'));
        $this->entityManager->flush();

        $rows = $this->repository->getMonthlyRevenueAtPlace($place, 12, $now, null);
        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[sprintf('%d-%02d', $row['year'], $row['month'])] = $row['total'];
        }

        $this->assertSame(100_000, $byMonth['2025-04'] ?? 0);
        $this->assertSame(300_000, $byMonth['2025-05'] ?? 0);
        $this->assertSame(50_000, $byMonth['2025-06'] ?? 0);
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

    private function createStorageType(Place $place): StorageType
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Test Type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 50_000,
            defaultPricePerMonth: 180_000,
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
            firstPaymentPrice: 500_000,
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

    private function createPayment(?Order $order, ?Contract $contract, Storage $storage, int $amount, \DateTimeImmutable $paidAt): Payment
    {
        $payment = new Payment(
            id: Uuid::v7(),
            order: $order,
            contract: $contract,
            storage: $storage,
            amount: $amount,
            paidAt: $paidAt,
            createdAt: $paidAt,
        );
        $this->entityManager->persist($payment);

        return $payment;
    }
}
