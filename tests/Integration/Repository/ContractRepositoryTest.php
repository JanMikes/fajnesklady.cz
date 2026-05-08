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
use App\Enum\TerminationReason;
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

    private function createOrder(
        User $user,
        Storage $storage,
        \DateTimeImmutable $startDate,
        ?\DateTimeImmutable $endDate,
        int $firstPaymentPrice = 10000,
    ): Order {
        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: null === $endDate ? RentalType::UNLIMITED : RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: $firstPaymentPrice,
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
        $tenant = $this->createUser('tenant-c-overlap1@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'COL1');

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
        $tenant = $this->createUser('tenant-c-unlimited@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'CUNL');

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
        $tenant = $this->createUser('tenant-c-term@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'CTRM');

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
        $tenant = $this->createUser('tenant-c-exp@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage1 = $this->createStorage($storageType, $place, 'CEXP1');
        $storage2 = $this->createStorage($storageType, $place, 'CEXP2');
        $storage3 = $this->createStorage($storageType, $place, 'CEXP3');

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
        $tenant = $this->createUser('tenant-c-active@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage1 = $this->createStorage($storageType, $place, 'CACT1');
        $storage2 = $this->createStorage($storageType, $place, 'CACT2');

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
        $tenant = $this->createUser('tenant-c-storage@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'CSTR');

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

    /**
     * Helper for findRequiringAdvanceNotice tests: builds an active recurring
     * contract with caller-supplied billing state.
     */
    private function createRecurringContract(
        string $emailSeed,
        \DateTimeImmutable $lastBilledAt,
        ?\DateTimeImmutable $nextBillingDate,
        ?\DateTimeImmutable $lastAdvanceNoticeSentAt = null,
        bool $terminated = false,
        bool $hasParentPaymentId = true,
    ): Contract {
        $tenant = $this->createUser($emailSeed.'@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, strtoupper(substr($emailSeed, 0, 4)));
        $order = $this->createOrder($tenant, $storage, $lastBilledAt->modify('-1 month'), null);
        $contract = $this->createContract($order, $tenant, $storage, $order->startDate, null);

        if ($hasParentPaymentId) {
            $contract->setRecurringPayment(
                'gp-parent-'.$emailSeed,
                $nextBillingDate,
                $nextBillingDate ?? $lastBilledAt,
            );
        }

        $contract->recordBillingCharge(
            $lastBilledAt,
            $nextBillingDate,
            $nextBillingDate ?? $lastBilledAt,
        );

        if (null !== $lastAdvanceNoticeSentAt) {
            $contract->recordAdvanceNoticeSent($lastAdvanceNoticeSentAt);
        }

        if ($terminated) {
            $contract->terminate(new \DateTimeImmutable('2025-01-01'), releaseStorage: false);
        }

        return $contract;
    }

    public function testFindRequiringAdvanceNoticeIncludesContractInWindowWithSixMonthGap(): void
    {
        $now = new \DateTimeImmutable('2026-05-05 12:00:00');

        $contract = $this->createRecurringContract(
            emailSeed: 'adv-included',
            lastBilledAt: $now->modify('-7 months'),
            nextBillingDate: $now->modify('+9 days'),
        );

        $this->entityManager->flush();

        $found = $this->repository->findRequiringAdvanceNotice($now);

        $foundIds = array_map(fn (Contract $c) => $c->id->toRfc4122(), $found);
        $this->assertContains($contract->id->toRfc4122(), $foundIds);
    }

    public function testFindRequiringAdvanceNoticeExcludesRecentlyChargedContract(): void
    {
        $now = new \DateTimeImmutable('2026-05-05 12:00:00');

        $contract = $this->createRecurringContract(
            emailSeed: 'adv-recent',
            lastBilledAt: $now->modify('-2 months'),
            nextBillingDate: $now->modify('+9 days'),
        );

        $this->entityManager->flush();

        $found = $this->repository->findRequiringAdvanceNotice($now);
        $foundIds = array_map(fn (Contract $c) => $c->id->toRfc4122(), $found);
        $this->assertNotContains($contract->id->toRfc4122(), $foundIds);
    }

    public function testFindRequiringAdvanceNoticeExcludesContractOutsideWindow(): void
    {
        $now = new \DateTimeImmutable('2026-05-05 12:00:00');

        // Next billing in 3 days — well before the 8-10 day window.
        $contract = $this->createRecurringContract(
            emailSeed: 'adv-soon',
            lastBilledAt: $now->modify('-7 months'),
            nextBillingDate: $now->modify('+3 days'),
        );

        $this->entityManager->flush();

        $found = $this->repository->findRequiringAdvanceNotice($now);
        $foundIds = array_map(fn (Contract $c) => $c->id->toRfc4122(), $found);
        $this->assertNotContains($contract->id->toRfc4122(), $foundIds);
    }

    public function testFindRequiringAdvanceNoticeExcludesContractWithRecentNotice(): void
    {
        $now = new \DateTimeImmutable('2026-05-05 12:00:00');

        $contract = $this->createRecurringContract(
            emailSeed: 'adv-notified',
            lastBilledAt: $now->modify('-7 months'),
            nextBillingDate: $now->modify('+9 days'),
            lastAdvanceNoticeSentAt: $now->modify('-30 days'),
        );

        $this->entityManager->flush();

        $found = $this->repository->findRequiringAdvanceNotice($now);
        $foundIds = array_map(fn (Contract $c) => $c->id->toRfc4122(), $found);
        $this->assertNotContains($contract->id->toRfc4122(), $foundIds);
    }

    public function testFindRequiringAdvanceNoticeExcludesTerminatedContract(): void
    {
        $now = new \DateTimeImmutable('2026-05-05 12:00:00');

        $contract = $this->createRecurringContract(
            emailSeed: 'adv-terminated',
            lastBilledAt: $now->modify('-7 months'),
            nextBillingDate: $now->modify('+9 days'),
            terminated: true,
        );

        $this->entityManager->flush();

        $found = $this->repository->findRequiringAdvanceNotice($now);
        $foundIds = array_map(fn (Contract $c) => $c->id->toRfc4122(), $found);
        $this->assertNotContains($contract->id->toRfc4122(), $foundIds);
    }

    public function testFindRequiringAdvanceNoticeExcludesContractWithoutRecurringSetup(): void
    {
        $now = new \DateTimeImmutable('2026-05-05 12:00:00');

        $contract = $this->createRecurringContract(
            emailSeed: 'adv-noparent',
            lastBilledAt: $now->modify('-7 months'),
            nextBillingDate: $now->modify('+9 days'),
            hasParentPaymentId: false,
        );

        $this->entityManager->flush();

        $found = $this->repository->findRequiringAdvanceNotice($now);
        $foundIds = array_map(fn (Contract $c) => $c->id->toRfc4122(), $found);
        $this->assertNotContains($contract->id->toRfc4122(), $foundIds);
    }

    public function testCountAndSumOverdueIncludeFailingActiveAndTerminatedDebt(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        // Wipe contracts so we can assert exact counts/sums in isolation (DAMA rolls back).
        $this->entityManager->getConnection()->executeStatement('DELETE FROM contract');

        $tenant = $this->createUser('overdue-mixed@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage1 = $this->createStorage($storageType, $place, 'OD1');
        $storage2 = $this->createStorage($storageType, $place, 'OD2');
        $storage3 = $this->createStorage($storageType, $place, 'OD3');
        $storage4 = $this->createStorage($storageType, $place, 'OD4');

        // Active failing contract — counts.
        $orderFail = $this->createOrder($tenant, $storage1, $now->modify('-30 days'), null);
        $contractFail = $this->createContract($orderFail, $tenant, $storage1, $now->modify('-30 days'), null);
        $contractFail->setRecurringPayment('parent-fail', $now->modify('-5 days'), $now->modify('-5 days'));
        $contractFail->recordFailedBillingAttempt($now->modify('-3 days'));

        // Terminated with debt — counts.
        $orderDebt = $this->createOrder($tenant, $storage2, $now->modify('-60 days'), $now->modify('-30 days'));
        $contractDebt = $this->createContract($orderDebt, $tenant, $storage2, $now->modify('-60 days'), $now->modify('-30 days'));
        $contractDebt->setOutstandingDebt(350000);
        $contractDebt->terminate($now->modify('-15 days'), TerminationReason::PAYMENT_FAILURE);

        // Healthy active recurring — does NOT count.
        $orderHealthy = $this->createOrder($tenant, $storage3, $now->modify('-10 days'), null);
        $this->createContract($orderHealthy, $tenant, $storage3, $now->modify('-10 days'), null)
            ->setRecurringPayment('parent-healthy', $now->modify('+5 days'), $now->modify('+5 days'));

        // Free contract whose recurring window has lapsed — should NOT count or contribute to the sum.
        // Free contracts skip charging entirely; mirroring OverdueChecker's isFree() filter keeps
        // the badge count and the page row count consistent.
        $orderFree = $this->createOrder($tenant, $storage4, $now->modify('-30 days'), null);
        $contractFree = $this->createContract($orderFree, $tenant, $storage4, $now->modify('-30 days'), null);
        $contractFree->applyIndividualMonthlyAmount(0, null, null, $now);
        $contractFree->setRecurringPayment('parent-free', $now->modify('-5 days'), $now->modify('-5 days'));
        $contractFree->recordFailedBillingAttempt($now->modify('-3 days'));

        $this->entityManager->flush();

        $count = $this->repository->countOverdueContracts($now);
        $sum = $this->repository->sumOverdueAmount($now);
        $userIds = $this->repository->findOverdueUserIds($now);

        // Failing + terminated-with-debt = 2; the free contract and the healthy one are excluded.
        $this->assertSame(2, $count);
        // Failing's monthly rate (10 000) + terminated debt (350 000); free contract excluded.
        $this->assertSame(10000 + 350000, $sum);
        $this->assertContains($tenant->id->toRfc4122(), $userIds);
    }

    public function testFindOverdueUserIdsRestrictedToSubsetReturnsEmptyForNonDebtor(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $debtor = $this->createUser('overdue-restrict-debtor@test.com');
        $clean = $this->createUser('overdue-restrict-clean@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'ODR1');

        $order = $this->createOrder($debtor, $storage, $now->modify('-30 days'), null);
        $contract = $this->createContract($order, $debtor, $storage, $now->modify('-30 days'), null);
        $contract->setRecurringPayment('parent-restrict', $now->modify('-5 days'), $now->modify('-5 days'));
        $contract->recordFailedBillingAttempt($now->modify('-3 days'));

        $this->entityManager->flush();

        $debtorOnly = $this->repository->findOverdueUserIds($now, [$debtor->id]);
        $cleanOnly = $this->repository->findOverdueUserIds($now, [$clean->id]);
        $emptyInput = $this->repository->findOverdueUserIds($now, []);

        $this->assertContains($debtor->id->toRfc4122(), $debtorOnly);
        $this->assertNotContains($clean->id->toRfc4122(), $cleanOnly);
        $this->assertSame([], $emptyInput);
    }

    public function testCountAndSumActiveRecurringAtPlaceScopedByOwner(): void
    {
        $tenant = $this->createUser('tenant-rec-place@test.com');
        $landlordA = $this->createUser('landlord-rec-A@test.com');
        $landlordB = $this->createUser('landlord-rec-B@test.com');
        $place = $this->createPlace();
        $st = $this->createStorageType();
        $storageA = $this->createStorage($st, $place, 'RPA', $landlordA);
        $storageB = $this->createStorage($st, $place, 'RPB', $landlordB);

        $orderA = $this->createOrder($tenant, $storageA, new \DateTimeImmutable('-30 days'), null);
        $orderA->markPaid(new \DateTimeImmutable('-29 days'));
        $contractA = $this->createContract($orderA, $tenant, $storageA, new \DateTimeImmutable('-30 days'), null);
        $contractA->setRecurringPayment('parent-A', new \DateTimeImmutable('+5 days'), new \DateTimeImmutable('-1 day'));

        $orderB = $this->createOrder($tenant, $storageB, new \DateTimeImmutable('-30 days'), null);
        $orderB->markPaid(new \DateTimeImmutable('-29 days'));
        $contractB = $this->createContract($orderB, $tenant, $storageB, new \DateTimeImmutable('-30 days'), null);
        $contractB->setRecurringPayment('parent-B', new \DateTimeImmutable('+5 days'), new \DateTimeImmutable('-1 day'));

        $this->entityManager->flush();

        $this->assertSame(2, $this->repository->countActiveRecurringAtPlace($place, null));
        $this->assertSame(1, $this->repository->countActiveRecurringAtPlace($place, $landlordA));

        // Each order's firstPaymentPrice is 10000 in the helper.
        $this->assertSame(20000, $this->repository->sumExpectedRecurringAtPlace($place, null));
        $this->assertSame(10000, $this->repository->sumExpectedRecurringAtPlace($place, $landlordA));
    }

    public function testSumExpectedRecurringAtPlaceHonoursIndividualMonthlyAmountOverride(): void
    {
        $tenant = $this->createUser('tenant-mrr-override@test.com');
        $landlord = $this->createUser('landlord-mrr-override@test.com');
        $place = $this->createPlace();
        $st = $this->createStorageType();
        $standardStorage = $this->createStorage($st, $place, 'MRR1', $landlord);
        $overrideStorage = $this->createStorage($st, $place, 'MRR2', $landlord);
        $freeStorage = $this->createStorage($st, $place, 'MRR3', $landlord);

        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        // Standard contract — order monthly = 150 000 halere (1 500 Kč), no override → contributes 150 000.
        $standardOrder = $this->createOrder($tenant, $standardStorage, $now->modify('-30 days'), null, 150000);
        $standardContract = $this->createContract($standardOrder, $tenant, $standardStorage, $now->modify('-30 days'), null);
        $standardContract->setRecurringPayment('parent-std', $now->modify('+5 days'), $now->modify('-1 day'));

        // Override contract — order monthly = 150 000 (storage default), override = 50 000 → contributes 50 000.
        $overrideOrder = $this->createOrder($tenant, $overrideStorage, $now->modify('-30 days'), null, 150000);
        $overrideContract = $this->createContract($overrideOrder, $tenant, $overrideStorage, $now->modify('-30 days'), null);
        $overrideContract->applyIndividualMonthlyAmount(50000, null, null, $now);
        $overrideContract->setRecurringPayment('parent-ovr', $now->modify('+5 days'), $now->modify('-1 day'));

        // Free contract — override = 0 → contributes 0 to the sum (auto-excluded by zero contribution).
        $freeOrder = $this->createOrder($tenant, $freeStorage, $now->modify('-30 days'), null, 150000);
        $freeContract = $this->createContract($freeOrder, $tenant, $freeStorage, $now->modify('-30 days'), null);
        $freeContract->applyIndividualMonthlyAmount(0, null, null, $now);
        $freeContract->setRecurringPayment('parent-free', $now->modify('+5 days'), $now->modify('-1 day'));

        $this->entityManager->flush();

        // Standard 150 000 + override 50 000 + free 0 = 200 000. Without the COALESCE fix, this
        // would have summed three times the order price (450 000) — the bug spec 028 fixes.
        $this->assertSame(200000, $this->repository->sumExpectedRecurringAtPlace($place, $landlord));
        $this->assertSame(200000, $this->repository->sumExpectedRecurringAtPlace($place, null));
    }

    public function testLoadCustomerStatsByUserIdsHonoursIndividualMonthlyAmountOverride(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $tenant = $this->createUser('cstats-override@test.com');
        $place = $this->createPlace();
        $st = $this->createStorageType();
        $standardStorage = $this->createStorage($st, $place, 'CSO1');
        $overrideStorage = $this->createStorage($st, $place, 'CSO2');
        $freeStorage = $this->createStorage($st, $place, 'CSO3');

        // Standard contract — order = 150 000, no override → contributes 150 000 to MRR.
        $standardOrder = $this->createOrder($tenant, $standardStorage, $now->modify('-30 days'), null, 150000);
        $this->createContract($standardOrder, $tenant, $standardStorage, $now->modify('-30 days'), null);

        // Override contract — order = 150 000 (storage default), override = 80 000 → contributes 80 000.
        $overrideOrder = $this->createOrder($tenant, $overrideStorage, $now->modify('-30 days'), null, 150000);
        $overrideContract = $this->createContract($overrideOrder, $tenant, $overrideStorage, $now->modify('-30 days'), null);
        $overrideContract->applyIndividualMonthlyAmount(80000, null, null, $now);

        // Free contract — order = 150 000, override = 0 → contributes 0.
        $freeOrder = $this->createOrder($tenant, $freeStorage, $now->modify('-30 days'), null, 150000);
        $freeContract = $this->createContract($freeOrder, $tenant, $freeStorage, $now->modify('-30 days'), null);
        $freeContract->applyIndividualMonthlyAmount(0, null, null, $now);

        $this->entityManager->flush();

        $stats = $this->repository->loadCustomerStatsByUserIds([$tenant->id], $now);

        $key = $tenant->id->toRfc4122();
        $this->assertSame(3, $stats[$key]['activeCount']);
        $this->assertSame(3, $stats[$key]['totalCount']);
        // 150 000 (standard) + 80 000 (override) + 0 (free) = 230 000.
        // Pre-fix this would have been 450 000 (the order's locked-in monthly × 3).
        $this->assertSame(230000, $stats[$key]['mrrInHaler']);
    }

    public function testLoadCustomerStatsByUserIdsAggregatesActiveTotalAndMrr(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $tenant = $this->createUser('cstats-tenant@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $unlimited = $this->createStorage($storageType, $place, 'CSU');
        $longLimited = $this->createStorage($storageType, $place, 'CSL');
        $shortLimited = $this->createStorage($storageType, $place, 'CSS');
        $terminated = $this->createStorage($storageType, $place, 'CST');

        // Active UNLIMITED contract @ 1500 Kč → counts toward MRR
        $orderUnlimited = $this->createOrder($tenant, $unlimited, $now->modify('-30 days'), null, 150000);
        $this->createContract($orderUnlimited, $tenant, $unlimited, $now->modify('-30 days'), null);

        // Active LIMITED ≥28d contract @ 800 Kč → counts toward MRR
        $orderLong = $this->createOrder($tenant, $longLimited, $now->modify('-10 days'), $now->modify('+50 days'), 80000);
        $this->createContract($orderLong, $tenant, $longLimited, $now->modify('-10 days'), $now->modify('+50 days'));

        // Active short LIMITED <28d @ 1200 Kč → NOT in MRR
        $orderShort = $this->createOrder($tenant, $shortLimited, $now->modify('-3 days'), $now->modify('+10 days'), 120000);
        $this->createContract($orderShort, $tenant, $shortLimited, $now->modify('-3 days'), $now->modify('+10 days'));

        // Terminated contract → in totalCount, not in activeCount or MRR
        $orderTerminated = $this->createOrder($tenant, $terminated, $now->modify('-90 days'), null, 200000);
        $contractTerminated = $this->createContract($orderTerminated, $tenant, $terminated, $now->modify('-90 days'), null);
        $contractTerminated->terminate($now->modify('-1 day'));

        $this->entityManager->flush();

        $stats = $this->repository->loadCustomerStatsByUserIds([$tenant->id], $now);

        $key = $tenant->id->toRfc4122();
        $this->assertArrayHasKey($key, $stats);
        $this->assertSame(3, $stats[$key]['activeCount']);
        $this->assertSame(4, $stats[$key]['totalCount']);
        $this->assertSame(150000 + 80000, $stats[$key]['mrrInHaler']);
    }

    public function testLoadCustomerStatsByUserIdsOmitsUsersWithoutContracts(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $userWithout = $this->createUser('cstats-blank@test.com');
        $this->entityManager->flush();

        $stats = $this->repository->loadCustomerStatsByUserIds([$userWithout->id], $now);

        $this->assertSame([], $stats);
    }

    public function testLoadCustomerStatsByUserIdsReturnsEmptyForEmptyInput(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $stats = $this->repository->loadCustomerStatsByUserIds([], $now);

        $this->assertSame([], $stats);
    }

    public function testLoadCustomerStatsByUserIdsHandlesFreeContractAsZeroMrr(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $tenant = $this->createUser('cstats-free@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'CFR');

        $order = $this->createOrder($tenant, $storage, $now->modify('-30 days'), null, 0);
        $this->createContract($order, $tenant, $storage, $now->modify('-30 days'), null);

        $this->entityManager->flush();

        $stats = $this->repository->loadCustomerStatsByUserIds([$tenant->id], $now);

        $key = $tenant->id->toRfc4122();
        $this->assertSame(1, $stats[$key]['activeCount']);
        $this->assertSame(0, $stats[$key]['mrrInHaler']);
    }

    public function testLoadCustomerStatsByUserIdsCountsLimitedContractAtExactly28DaysAsRecurring(): void
    {
        // 28 days is the boundary set by PriceCalculator::WEEKLY_THRESHOLD_DAYS — the
        // recurring predicate in loadCustomerStatsByUserIds is `(c.end_date - c.start_date) >= 28`,
        // so a contract with exactly 28 days IS recurring and MUST contribute to MRR.
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $tenant = $this->createUser('cstats-28-day@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'C28');

        $start = $now->modify('-1 day');
        $end = $start->modify('+28 days');
        $order = $this->createOrder($tenant, $storage, $start, $end, 90000);
        $this->createContract($order, $tenant, $storage, $start, $end);

        $this->entityManager->flush();

        $stats = $this->repository->loadCustomerStatsByUserIds([$tenant->id], $now);

        $key = $tenant->id->toRfc4122();
        $this->assertSame(1, $stats[$key]['activeCount']);
        $this->assertSame(90000, $stats[$key]['mrrInHaler']);
    }

    public function testLoadCustomerStatsByUserIdsExcludesLimitedContractAt27DaysFromMrr(): void
    {
        // One day below the 28-day boundary — short LIMITED, must NOT contribute to MRR
        // (still counts toward activeCount and totalCount).
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $tenant = $this->createUser('cstats-27-day@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'C27');

        $start = $now->modify('-1 day');
        $end = $start->modify('+27 days');
        $order = $this->createOrder($tenant, $storage, $start, $end, 90000);
        $this->createContract($order, $tenant, $storage, $start, $end);

        $this->entityManager->flush();

        $stats = $this->repository->loadCustomerStatsByUserIds([$tenant->id], $now);

        $key = $tenant->id->toRfc4122();
        $this->assertSame(1, $stats[$key]['activeCount']);
        $this->assertSame(1, $stats[$key]['totalCount']);
        $this->assertSame(0, $stats[$key]['mrrInHaler']);
    }

    public function testFindActiveContractUserIdsSubqueryReturnsSentinelWhenEmpty(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        // Wipe contracts in this test's transaction (DAMA rolls back).
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('DELETE FROM contract');

        $ids = $this->repository->findActiveContractUserIdsSubquery($now);

        $this->assertSame(['00000000-0000-0000-0000-000000000000'], $ids);
    }

    public function testFindActiveContractUserIdsSubqueryReturnsActiveUsers(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $tenant = $this->createUser('active-subquery-tenant@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'CAS');

        $order = $this->createOrder($tenant, $storage, $now->modify('-10 days'), null);
        $this->createContract($order, $tenant, $storage, $now->modify('-10 days'), null);

        $this->entityManager->flush();

        $ids = $this->repository->findActiveContractUserIdsSubquery($now);

        $this->assertContains($tenant->id->toRfc4122(), $ids);
        $this->assertNotContains('00000000-0000-0000-0000-000000000000', $ids);
    }

    public function testFindExpiringWithinDaysAtPlaceScopedByPlaceAndOwner(): void
    {
        $tenant = $this->createUser('tenant-expiring-place@test.com');
        $landlord = $this->createUser('landlord-expiring@test.com');
        $stranger = $this->createUser('stranger-expiring@test.com');
        $placeA = $this->createPlace();
        $placeB = $this->createPlace();
        $st = $this->createStorageType();

        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $myStorageA = $this->createStorage($st, $placeA, 'EXA1', $landlord);
        $strangerStorageA = $this->createStorage($st, $placeA, 'EXA2', $stranger);
        $storageB = $this->createStorage($st, $placeB, 'EXB1', $landlord);

        // expires within 30d at placeA, owned by landlord — IN
        $orderInA = $this->createOrder($tenant, $myStorageA, $now, $now->modify('+10 days'));
        $contractInA = $this->createContract($orderInA, $tenant, $myStorageA, $now, $now->modify('+10 days'));

        // expires within 30d at placeA, owned by stranger — IN for null, OUT for landlord
        $orderStranger = $this->createOrder($tenant, $strangerStorageA, $now, $now->modify('+15 days'));
        $contractStranger = $this->createContract($orderStranger, $tenant, $strangerStorageA, $now, $now->modify('+15 days'));

        // expires within 30d at placeB — different place, OUT
        $orderOtherPlace = $this->createOrder($tenant, $storageB, $now, $now->modify('+5 days'));
        $contractOtherPlace = $this->createContract($orderOtherPlace, $tenant, $storageB, $now, $now->modify('+5 days'));

        // expires past 30d at placeA — OUT
        $orderTooFar = $this->createOrder($tenant, $myStorageA, $now, $now->modify('+45 days'));
        $contractTooFar = $this->createContract($orderTooFar, $tenant, $myStorageA, $now, $now->modify('+45 days'));

        $this->entityManager->flush();

        $admin = $this->repository->findExpiringWithinDaysAtPlace(30, $now, $placeA, null);
        $adminIds = array_map(fn (Contract $c) => $c->id->toRfc4122(), $admin);
        $this->assertContains($contractInA->id->toRfc4122(), $adminIds);
        $this->assertContains($contractStranger->id->toRfc4122(), $adminIds);
        $this->assertNotContains($contractOtherPlace->id->toRfc4122(), $adminIds);
        $this->assertNotContains($contractTooFar->id->toRfc4122(), $adminIds);

        $landlordOnly = $this->repository->findExpiringWithinDaysAtPlace(30, $now, $placeA, $landlord);
        $landlordIds = array_map(fn (Contract $c) => $c->id->toRfc4122(), $landlordOnly);
        $this->assertContains($contractInA->id->toRfc4122(), $landlordIds);
        $this->assertNotContains($contractStranger->id->toRfc4122(), $landlordIds);
    }

    public function testFindActiveByStoragesReturnsCurrentlyActiveContracts(): void
    {
        $tenant = $this->createUser('tenant-cas-active@test.com');
        $place = $this->createPlace();
        $st = $this->createStorageType();
        $storageActive = $this->createStorage($st, $place, 'CAS1');
        $storageFuture = $this->createStorage($st, $place, 'CAS2');
        $storageTerminated = $this->createStorage($st, $place, 'CAS3');

        $now = new \DateTimeImmutable('2025-06-15');

        $orderActive = $this->createOrder($tenant, $storageActive, $now->modify('-10 days'), $now->modify('+10 days'));
        $active = $this->createContract($orderActive, $tenant, $storageActive, $now->modify('-10 days'), $now->modify('+10 days'));

        $orderFuture = $this->createOrder($tenant, $storageFuture, $now->modify('+5 days'), $now->modify('+30 days'));
        $this->createContract($orderFuture, $tenant, $storageFuture, $now->modify('+5 days'), $now->modify('+30 days'));

        $orderTerminated = $this->createOrder($tenant, $storageTerminated, $now->modify('-30 days'), $now->modify('+10 days'));
        $terminated = $this->createContract($orderTerminated, $tenant, $storageTerminated, $now->modify('-30 days'), $now->modify('+10 days'));
        $terminated->terminate($now->modify('-1 day'));

        $this->entityManager->flush();

        $found = $this->repository->findActiveByStorages([$storageActive, $storageFuture, $storageTerminated], $now);
        $ids = array_map(fn (Contract $c) => $c->id->toRfc4122(), $found);

        $this->assertCount(1, $found);
        $this->assertContains($active->id->toRfc4122(), $ids);
    }

    public function testFindActiveByStoragesReturnsEmptyForEmptyInput(): void
    {
        $now = new \DateTimeImmutable('2025-06-15');

        $this->assertSame([], $this->repository->findActiveByStorages([], $now));
    }

    public function testFindOverlappingByStoragesReturnsAllNonTerminatedSpansOverlappingRange(): void
    {
        $tenant = $this->createUser('tenant-overlap-bulk@test.com');
        $place = $this->createPlace();
        $st = $this->createStorageType();
        $storage = $this->createStorage($st, $place, 'COB1');

        $rangeFrom = new \DateTimeImmutable('2025-06-01');
        $rangeTo = new \DateTimeImmutable('2025-06-30');

        // Inside range
        $insideOrder = $this->createOrder($tenant, $storage, new \DateTimeImmutable('2025-06-10'), new \DateTimeImmutable('2025-06-20'));
        $inside = $this->createContract($insideOrder, $tenant, $storage, new \DateTimeImmutable('2025-06-10'), new \DateTimeImmutable('2025-06-20'));

        // Strictly before range
        $beforeOrder = $this->createOrder($tenant, $storage, new \DateTimeImmutable('2025-04-01'), new \DateTimeImmutable('2025-05-01'));
        $this->createContract($beforeOrder, $tenant, $storage, new \DateTimeImmutable('2025-04-01'), new \DateTimeImmutable('2025-05-01'));

        // Unlimited contract starting before range — overlaps
        $unlimitedOrder = $this->createOrder($tenant, $storage, new \DateTimeImmutable('2025-05-15'), null);
        $unlimited = $this->createContract($unlimitedOrder, $tenant, $storage, new \DateTimeImmutable('2025-05-15'), null);

        $this->entityManager->flush();

        $found = $this->repository->findOverlappingByStorages([$storage], $rangeFrom, $rangeTo);
        $ids = array_map(fn (Contract $c) => $c->id->toRfc4122(), $found);

        $this->assertContains($inside->id->toRfc4122(), $ids);
        $this->assertContains($unlimited->id->toRfc4122(), $ids);
        $this->assertCount(2, $ids);
    }

    public function testFindNextStartByStoragesReturnsEarliestFutureStartPerStorage(): void
    {
        $tenant = $this->createUser('tenant-next-start@test.com');
        $place = $this->createPlace();
        $st = $this->createStorageType();
        $storageA = $this->createStorage($st, $place, 'NSA1');
        $storageB = $this->createStorage($st, $place, 'NSB1');

        $now = new \DateTimeImmutable('2025-06-15');

        // storageA: two future contracts, the earlier one wins
        $orderA1 = $this->createOrder($tenant, $storageA, $now->modify('+10 days'), $now->modify('+40 days'));
        $this->createContract($orderA1, $tenant, $storageA, $now->modify('+10 days'), $now->modify('+40 days'));
        $orderA2 = $this->createOrder($tenant, $storageA, $now->modify('+20 days'), $now->modify('+50 days'));
        $this->createContract($orderA2, $tenant, $storageA, $now->modify('+20 days'), $now->modify('+50 days'));

        // storageA also has a contract that started in the past — NOT future.
        $orderAPast = $this->createOrder($tenant, $storageA, $now->modify('-30 days'), $now->modify('-1 day'));
        $this->createContract($orderAPast, $tenant, $storageA, $now->modify('-30 days'), $now->modify('-1 day'));

        // storageB: no future contracts
        $orderBPast = $this->createOrder($tenant, $storageB, $now->modify('-30 days'), $now->modify('-1 day'));
        $this->createContract($orderBPast, $tenant, $storageB, $now->modify('-30 days'), $now->modify('-1 day'));

        $this->entityManager->flush();

        $result = $this->repository->findNextStartByStorages([$storageA, $storageB], $now);

        $this->assertArrayHasKey($storageA->id->toRfc4122(), $result);
        $this->assertEquals($now->modify('+10 days'), $result[$storageA->id->toRfc4122()]);
        $this->assertArrayNotHasKey($storageB->id->toRfc4122(), $result);
    }

    public function testSumExpectedRecurringByLandlordHonoursIndividualMonthlyAmountOverride(): void
    {
        $tenant = $this->createUser('tenant-mrr-landlord-override@test.com');
        $landlord = $this->createUser('landlord-mrr-override-byll@test.com');
        $place = $this->createPlace();
        $st = $this->createStorageType();
        $standardStorage = $this->createStorage($st, $place, 'LMR1', $landlord);
        $overrideStorage = $this->createStorage($st, $place, 'LMR2', $landlord);
        $freeStorage = $this->createStorage($st, $place, 'LMR3', $landlord);

        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        // Standard contract — order monthly = 150 000 halere, no override → contributes 150 000.
        $standardOrder = $this->createOrder($tenant, $standardStorage, $now->modify('-30 days'), null, 150000);
        $standardContract = $this->createContract($standardOrder, $tenant, $standardStorage, $now->modify('-30 days'), null);
        $standardContract->setRecurringPayment('parent-byll-std', $now->modify('+5 days'), $now->modify('-1 day'));

        // Override contract — order = 150 000, override = 50 000 → contributes 50 000.
        $overrideOrder = $this->createOrder($tenant, $overrideStorage, $now->modify('-30 days'), null, 150000);
        $overrideContract = $this->createContract($overrideOrder, $tenant, $overrideStorage, $now->modify('-30 days'), null);
        $overrideContract->applyIndividualMonthlyAmount(50000, null, null, $now);
        $overrideContract->setRecurringPayment('parent-byll-ovr', $now->modify('+5 days'), $now->modify('-1 day'));

        // Free contract — override = 0 → contributes 0 to the sum (auto-excluded by zero contribution).
        $freeOrder = $this->createOrder($tenant, $freeStorage, $now->modify('-30 days'), null, 150000);
        $freeContract = $this->createContract($freeOrder, $tenant, $freeStorage, $now->modify('-30 days'), null);
        $freeContract->applyIndividualMonthlyAmount(0, null, null, $now);
        $freeContract->setRecurringPayment('parent-byll-free', $now->modify('+5 days'), $now->modify('-1 day'));

        $this->entityManager->flush();

        // Standard 150 000 + override 50 000 + free 0 = 200 000. Without the COALESCE fix,
        // this would have summed three times the order price (450 000).
        $this->assertSame(200000, $this->repository->sumExpectedRecurringByLandlord($landlord));
    }

    public function testSumExpectedRecurringAllHonoursIndividualMonthlyAmountOverride(): void
    {
        // sumExpectedRecurringAll has no scoping argument and therefore picks up
        // any fixture-loaded recurring contracts as a baseline. We assert on the
        // delta introduced by this test rather than an absolute value so the test
        // is resilient to fixture changes.
        $baseline = $this->repository->sumExpectedRecurringAll();

        $tenant = $this->createUser('tenant-mrr-all-override@test.com');
        $landlord = $this->createUser('landlord-mrr-all-override@test.com');
        $place = $this->createPlace();
        $st = $this->createStorageType();
        $standardStorage = $this->createStorage($st, $place, 'AMR1', $landlord);
        $overrideStorage = $this->createStorage($st, $place, 'AMR2', $landlord);
        $freeStorage = $this->createStorage($st, $place, 'AMR3', $landlord);

        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        // Standard contract — order = 150 000, no override → contributes 150 000.
        $standardOrder = $this->createOrder($tenant, $standardStorage, $now->modify('-30 days'), null, 150000);
        $standardContract = $this->createContract($standardOrder, $tenant, $standardStorage, $now->modify('-30 days'), null);
        $standardContract->setRecurringPayment('parent-all-std', $now->modify('+5 days'), $now->modify('-1 day'));

        // Override contract — order = 150 000, override = 80 000 → contributes 80 000.
        $overrideOrder = $this->createOrder($tenant, $overrideStorage, $now->modify('-30 days'), null, 150000);
        $overrideContract = $this->createContract($overrideOrder, $tenant, $overrideStorage, $now->modify('-30 days'), null);
        $overrideContract->applyIndividualMonthlyAmount(80000, null, null, $now);
        $overrideContract->setRecurringPayment('parent-all-ovr', $now->modify('+5 days'), $now->modify('-1 day'));

        // Free contract — override = 0 → contributes 0.
        $freeOrder = $this->createOrder($tenant, $freeStorage, $now->modify('-30 days'), null, 150000);
        $freeContract = $this->createContract($freeOrder, $tenant, $freeStorage, $now->modify('-30 days'), null);
        $freeContract->applyIndividualMonthlyAmount(0, null, null, $now);
        $freeContract->setRecurringPayment('parent-all-free', $now->modify('+5 days'), $now->modify('-1 day'));

        $this->entityManager->flush();

        // Delta = standard 150 000 + override 80 000 + free 0 = 230 000.
        // Pre-fix the delta would have been 450 000 (the order's locked-in monthly × 3),
        // because the override + free amounts would have been ignored.
        $this->assertSame($baseline + 230000, $this->repository->sumExpectedRecurringAll());
    }
}
