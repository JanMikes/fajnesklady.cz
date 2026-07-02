<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Service\AtRiskContractChecker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class AtRiskContractCheckerTest extends KernelTestCase
{
    private AtRiskContractChecker $atRiskContractChecker;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->atRiskContractChecker = $container->get(AtRiskContractChecker::class);
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
            place: $this->createPlace(),
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

    private function createOrder(
        User $user,
        Storage $storage,
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        BillingMode $billingMode = BillingMode::MANUAL_RECURRING,
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
        // Bank-transfer fixed-term by default so the order blocks only its own
        // window — AUTO_RECURRING orders block open-endedly (availability guarantee).
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

    public function testNoLimitedContractsReturnsEmptyArray(): void
    {
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $this->createStorage($storageType, $place, 'A1');
        $this->entityManager->flush();

        $result = $this->atRiskContractChecker->findAtRiskContracts(
            $storageType,
            new \DateTimeImmutable()
        );

        $this->assertSame([], $result);
    }

    public function testContractWithPlentyOfAvailabilityNotAtRisk(): void
    {
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();

        // Create 3 storages
        $storage1 = $this->createStorage($storageType, $place, 'A1');
        $this->createStorage($storageType, $place, 'A2');
        $this->createStorage($storageType, $place, 'A3');

        // Create fixed-term contract for storage1 ending in 30 days
        $endDate = (new \DateTimeImmutable())->modify('+30 days');
        $order = $this->createOrder($tenant, $storage1, new \DateTimeImmutable(), $endDate);
        $this->createContract($order, $tenant, $storage1, new \DateTimeImmutable(), $endDate);

        $this->entityManager->flush();

        // At end date, 3 storages available (including storage1 becoming free)
        // So this contract is NOT at risk
        $result = $this->atRiskContractChecker->findAtRiskContracts(
            $storageType,
            new \DateTimeImmutable()
        );

        $this->assertSame([], $result);
    }

    public function testContractIsAtRiskWhenOnlyOneAvailable(): void
    {
        $tenant1 = $this->createUser('tenant1@test.com');
        $tenant2 = $this->createUser('tenant2@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();

        // Create 2 storages
        $storage1 = $this->createStorage($storageType, $place, 'A1');
        $storage2 = $this->createStorage($storageType, $place, 'A2');

        // Create fixed-term contract for storage1 ending in 30 days
        $endDate1 = (new \DateTimeImmutable())->modify('+30 days');
        $order1 = $this->createOrder($tenant1, $storage1, new \DateTimeImmutable(), $endDate1);
        $this->createContract($order1, $tenant1, $storage1, new \DateTimeImmutable(), $endDate1);

        // Create long fixed-term contract for storage2 (blocks it far beyond tenant1's end date)
        $endDate2 = (new \DateTimeImmutable())->modify('+2 years');
        $order2 = $this->createOrder($tenant2, $storage2, new \DateTimeImmutable(), $endDate2);
        $this->createContract($order2, $tenant2, $storage2, new \DateTimeImmutable(), $endDate2);

        $this->entityManager->flush();

        // At tenant1's end date, only storage1 will be available (becoming free)
        // So tenant1's contract IS at risk
        $result = $this->atRiskContractChecker->findAtRiskContracts(
            $storageType,
            new \DateTimeImmutable()
        );

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]->user->id->equals($tenant1->id));
    }

    public function testGuaranteedRecurringContractNotConsideredAtRisk(): void
    {
        $tenant = $this->createUser('tenant@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();

        $storage = $this->createStorage($storageType, $place, 'A1');

        // Card-recurring contract with a live token: the availability guarantee
        // blocks its storage open-endedly, so it never frees up for others and
        // its holder is never at risk.
        $endDate = (new \DateTimeImmutable())->modify('+12 months');
        $order = $this->createOrder($tenant, $storage, new \DateTimeImmutable(), $endDate, BillingMode::AUTO_RECURRING);
        $contract = $this->createContract($order, $tenant, $storage, new \DateTimeImmutable(), $endDate);
        $contract->setRecurringPayment('gopay-parent-at-risk', null, $endDate);

        $this->entityManager->flush();

        $result = $this->atRiskContractChecker->findAtRiskContracts(
            $storageType,
            new \DateTimeImmutable()
        );

        $this->assertSame([], $result);
    }

    public function testMultipleAtRiskContracts(): void
    {
        $tenant1 = $this->createUser('tenant1@test.com');
        $tenant2 = $this->createUser('tenant2@test.com');
        $tenant3 = $this->createUser('tenant3@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();

        // Create 3 storages
        $storage1 = $this->createStorage($storageType, $place, 'A1');
        $storage2 = $this->createStorage($storageType, $place, 'A2');
        $storage3 = $this->createStorage($storageType, $place, 'A3');

        // Create fixed-term contracts for all storages with different end dates
        $endDate1 = (new \DateTimeImmutable())->modify('+30 days');
        $endDate2 = (new \DateTimeImmutable())->modify('+60 days');
        $endDate3 = (new \DateTimeImmutable())->modify('+90 days');

        $order1 = $this->createOrder($tenant1, $storage1, new \DateTimeImmutable(), $endDate1);
        $this->createContract($order1, $tenant1, $storage1, new \DateTimeImmutable(), $endDate1);

        $order2 = $this->createOrder($tenant2, $storage2, new \DateTimeImmutable(), $endDate2);
        $this->createContract($order2, $tenant2, $storage2, new \DateTimeImmutable(), $endDate2);

        $order3 = $this->createOrder($tenant3, $storage3, new \DateTimeImmutable(), $endDate3);
        $this->createContract($order3, $tenant3, $storage3, new \DateTimeImmutable(), $endDate3);

        $this->entityManager->flush();

        $result = $this->atRiskContractChecker->findAtRiskContracts(
            $storageType,
            new \DateTimeImmutable()
        );

        // Only contract1 (ending first) is at risk because:
        // - At day 31: only storage1 becomes free (others still occupied) → at risk
        // - At day 61: storage1 + storage2 are free → NOT at risk (has alternative)
        // - At day 91: all 3 free → NOT at risk (has alternatives)
        $this->assertCount(1, $result);
        $this->assertTrue($result[0]->user->id->equals($tenant1->id));
    }
}
