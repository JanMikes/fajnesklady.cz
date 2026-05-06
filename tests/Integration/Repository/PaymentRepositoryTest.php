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
