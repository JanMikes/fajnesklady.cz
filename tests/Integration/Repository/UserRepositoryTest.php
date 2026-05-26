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
use App\Exception\UserNotFound;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

class UserRepositoryTest extends KernelTestCase
{
    private UserRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(UserRepository::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
    }

    public function testSaveUser(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'test@example.com', 'password123', 'Test', 'User', $now);

        $this->repository->save($user);
        $this->entityManager->flush();

        $foundUser = $this->repository->get($user->id);
        $this->assertSame($user->email, $foundUser->email);
        $this->assertSame($user->firstName, $foundUser->firstName);
        $this->assertSame($user->lastName, $foundUser->lastName);
    }

    public function testGet(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'findbyid@example.com', 'password123', 'Test', 'User', $now);
        $this->repository->save($user);
        $this->entityManager->flush();

        $foundUser = $this->repository->get($user->id);

        $this->assertEquals($user->id, $foundUser->id);
    }

    public function testGetThrowsForNonexistent(): void
    {
        $nonexistentId = Uuid::v7();

        $this->expectException(UserNotFound::class);

        $this->repository->get($nonexistentId);
    }

    public function testFindByEmail(): void
    {
        $now = new \DateTimeImmutable();
        $email = 'findbyemail@example.com';
        $user = new User(Uuid::v7(), $email, 'password123', 'Test', 'User', $now);
        $this->repository->save($user);
        $this->entityManager->flush();

        $foundUser = $this->repository->findByEmail($email);

        $this->assertNotNull($foundUser);
        $this->assertSame($email, $foundUser->email);
    }

    public function testFindByEmailReturnsNullForNonexistent(): void
    {
        $foundUser = $this->repository->findByEmail('nonexistent@example.com');

        $this->assertNull($foundUser);
    }

    public function testFindAll(): void
    {
        $now = new \DateTimeImmutable();
        $initialCount = count($this->repository->findAll());

        // Create multiple users
        $user1 = new User(Uuid::v7(), 'user1@example.com', 'password123', 'User', 'One', $now);
        $user2 = new User(Uuid::v7(), 'user2@example.com', 'password123', 'User', 'Two', $now);
        $user3 = new User(Uuid::v7(), 'user3@example.com', 'password123', 'User', 'Three', $now);

        $this->repository->save($user1);
        $this->repository->save($user2);
        $this->repository->save($user3);
        $this->entityManager->flush();

        $users = $this->repository->findAll();

        $this->assertCount($initialCount + 3, $users);
        // Verify all our newly created users are returned
        $emails = array_map(fn (User $u) => $u->email, $users);
        $this->assertContains('user1@example.com', $emails);
        $this->assertContains('user2@example.com', $emails);
        $this->assertContains('user3@example.com', $emails);
    }

    public function testFindAllPaginated(): void
    {
        $now = new \DateTimeImmutable();
        $initialCount = count($this->repository->findAll());

        // Create 5 users
        for ($i = 1; $i <= 5; ++$i) {
            $user = new User(Uuid::v7(), "paginated{$i}@example.com", 'password123', 'User', (string) $i, $now);
            $this->repository->save($user);
        }
        $this->entityManager->flush();

        $totalCount = $initialCount + 5;
        $limit = 2;

        // Get page 1 with limit 2
        $page1Users = $this->repository->findAllPaginated(1, $limit);
        $this->assertCount($limit, $page1Users);

        // Get page 2 with limit 2
        $page2Users = $this->repository->findAllPaginated(2, $limit);
        $this->assertCount($limit, $page2Users);

        // Verify users are different between pages
        $this->assertNotEquals($page1Users[0]->id, $page2Users[0]->id);

        // Get last page - calculate expected count
        $lastPage = (int) ceil($totalCount / $limit);
        $lastPageUsers = $this->repository->findAllPaginated($lastPage, $limit);
        $expectedLastPageCount = $totalCount % $limit ?: $limit;
        $this->assertCount($expectedLastPageCount, $lastPageUsers);
    }

    public function testFindAllPaginatedOrderedByCreatedAtDesc(): void
    {
        $now = new \DateTimeImmutable();
        // Get initial users from fixtures (created during bootstrap)
        $fixtureUsers = $this->repository->findAll();
        $fixtureEmails = array_map(fn (User $u) => $u->email, $fixtureUsers);

        // Create 3 new users - these will be more recently created than fixtures
        $user1 = new User(Uuid::v7(), 'order1@example.com', 'password123', 'User', 'One', $now);
        $user2 = new User(Uuid::v7(), 'order2@example.com', 'password123', 'User', 'Two', $now);
        $user3 = new User(Uuid::v7(), 'order3@example.com', 'password123', 'User', 'Three', $now);

        $this->repository->save($user1);
        $this->repository->save($user2);
        $this->repository->save($user3);
        $this->entityManager->flush();

        $users = $this->repository->findAllPaginated(1, 10);

        // Newly created users should appear before fixture users
        // First 3 results should be our new users (in some order - timestamps may be identical)
        $firstThreeEmails = array_map(fn (User $u) => $u->email, array_slice($users, 0, 3));
        $this->assertContains('order1@example.com', $firstThreeEmails);
        $this->assertContains('order2@example.com', $firstThreeEmails);
        $this->assertContains('order3@example.com', $firstThreeEmails);

        // Remaining users should be the fixture users
        $remainingEmails = array_map(fn (User $u) => $u->email, array_slice($users, 3));
        foreach ($remainingEmails as $email) {
            $this->assertContains($email, $fixtureEmails);
        }
    }

    public function testFindOverduePaginatedAndCountReturnOnlyDebtorUsers(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $debtor = $this->createDebtorWithFailingContract('debtor-overdue@example.com', $now);
        $clean = new User(Uuid::v7(), 'clean-overdue@example.com', 'password', 'Clean', 'User', $now);
        $this->entityManager->persist($clean);
        $this->entityManager->flush();

        $debtorUsers = $this->repository->findOverduePaginated(1, 50, $now);
        $debtorEmails = array_map(static fn (User $u) => $u->email, $debtorUsers);
        $count = $this->repository->countOverdueUsers($now);

        $this->assertContains('debtor-overdue@example.com', $debtorEmails);
        $this->assertNotContains('clean-overdue@example.com', $debtorEmails);
        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertSame($count, count($this->repository->findOverduePaginated(1, 1000, $now)));
    }

    private function createDebtorWithFailingContract(string $email, \DateTimeImmutable $now): User
    {
        $debtor = new User(Uuid::v7(), $email, 'password', 'Debt', 'Or', $now);
        $place = new Place(Uuid::v7(), 'Place', 'Address', 'City', '00000', null, $now);
        $storageType = new StorageType(Uuid::v7(), $place, 'Box', 100, 100, 100, 10000, 35000, 35000, 35000 * 12, $now);
        $storage = new Storage(
            Uuid::v7(),
            'OD-'.bin2hex(random_bytes(2)),
            ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            $storageType,
            $place,
            $now,
        );
        $order = new Order(
            Uuid::v7(),
            $debtor,
            $storage,
            RentalType::UNLIMITED,
            PaymentFrequency::MONTHLY,
            $now->modify('-30 days'),
            null,
            35000,
            $now->modify('-23 days'),
            $now->modify('-30 days'),
        );
        $contract = new Contract(
            Uuid::v7(),
            $order,
            $debtor,
            $storage,
            RentalType::UNLIMITED,
            $now->modify('-30 days'),
            null,
            $now->modify('-30 days'),
        );
        $contract->setOutstandingDebt(35000);
        $contract->terminate($now->modify('-10 days'), TerminationReason::PAYMENT_FAILURE);

        $this->entityManager->persist($debtor);
        $this->entityManager->persist($place);
        $this->entityManager->persist($storageType);
        $this->entityManager->persist($storage);
        $this->entityManager->persist($order);
        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        return $debtor;
    }

    public function testFindWithActiveContractsPaginatedAndCount(): void
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $tenant = new User(Uuid::v7(), 'active-tenant@example.com', 'password', 'Active', 'Tenant', $now);
        $bystander = new User(Uuid::v7(), 'no-contract@example.com', 'password', 'No', 'Contract', $now);
        $place = new Place(Uuid::v7(), 'Place A', 'Address', 'City', '00000', null, $now);
        $storageType = new StorageType(Uuid::v7(), $place, 'Box', 100, 100, 100, 10000, 35000, 35000, 35000 * 12, $now);
        $storage = new Storage(
            Uuid::v7(),
            'AC-'.bin2hex(random_bytes(2)),
            ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            $storageType,
            $place,
            $now,
        );
        $order = new Order(
            Uuid::v7(),
            $tenant,
            $storage,
            RentalType::UNLIMITED,
            PaymentFrequency::MONTHLY,
            $now->modify('-30 days'),
            null,
            35000,
            $now->modify('+7 days'),
            $now->modify('-30 days'),
        );
        $contract = new Contract(
            Uuid::v7(),
            $order,
            $tenant,
            $storage,
            RentalType::UNLIMITED,
            $now->modify('-30 days'),
            null,
            $now->modify('-30 days'),
        );

        $this->entityManager->persist($tenant);
        $this->entityManager->persist($bystander);
        $this->entityManager->persist($place);
        $this->entityManager->persist($storageType);
        $this->entityManager->persist($storage);
        $this->entityManager->persist($order);
        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        $activeUsers = $this->repository->findWithActiveContractsPaginated(1, 100, $now);
        $activeEmails = array_map(static fn (User $u) => $u->email, $activeUsers);
        $inactiveUsers = $this->repository->findWithoutActiveContractsPaginated(1, 100, $now);
        $inactiveEmails = array_map(static fn (User $u) => $u->email, $inactiveUsers);

        $this->assertContains('active-tenant@example.com', $activeEmails);
        $this->assertNotContains('no-contract@example.com', $activeEmails);

        $this->assertContains('no-contract@example.com', $inactiveEmails);
        $this->assertNotContains('active-tenant@example.com', $inactiveEmails);

        $this->assertSame(
            $this->repository->countTotal(),
            $this->repository->countWithActiveContracts($now) + $this->repository->countWithoutActiveContracts($now),
        );
    }

    public function testFindWithActiveContractsPaginatedReturnsOnlyUsersWithActiveContracts(): void
    {
        // Verify the active-contracts paginator surfaces ONLY users with at least one
        // non-terminated, not-yet-expired contract — and excludes terminated-only users
        // and never-rented users alike. Lives alongside testFindWithActiveContractsPaginatedAndCount,
        // which only checks one user of each shape; this test specifically guards the
        // "terminated contract should NOT make the user count as active" branch.
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $activeTenant = new User(Uuid::v7(), 'paginated-active@example.com', 'password', 'Act', 'Tenant', $now);
        $terminatedTenant = new User(Uuid::v7(), 'paginated-terminated@example.com', 'password', 'Trm', 'Tenant', $now);
        $neverRented = new User(Uuid::v7(), 'paginated-never@example.com', 'password', 'Never', 'Rented', $now);

        $place = new Place(Uuid::v7(), 'Paginated Place', 'Address', 'City', '00000', null, $now);
        $storageType = new StorageType(Uuid::v7(), $place, 'Box', 100, 100, 100, 10000, 35000, 35000, 35000 * 12, $now);
        $storageActive = new Storage(
            Uuid::v7(),
            'PA-'.bin2hex(random_bytes(2)),
            ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            $storageType,
            $place,
            $now,
        );
        $storageTerminated = new Storage(
            Uuid::v7(),
            'PT-'.bin2hex(random_bytes(2)),
            ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            $storageType,
            $place,
            $now,
        );

        $orderActive = new Order(
            Uuid::v7(),
            $activeTenant,
            $storageActive,
            RentalType::UNLIMITED,
            PaymentFrequency::MONTHLY,
            $now->modify('-30 days'),
            null,
            35000,
            $now->modify('+7 days'),
            $now->modify('-30 days'),
        );
        $contractActive = new Contract(
            Uuid::v7(),
            $orderActive,
            $activeTenant,
            $storageActive,
            RentalType::UNLIMITED,
            $now->modify('-30 days'),
            null,
            $now->modify('-30 days'),
        );

        $orderTerminated = new Order(
            Uuid::v7(),
            $terminatedTenant,
            $storageTerminated,
            RentalType::UNLIMITED,
            PaymentFrequency::MONTHLY,
            $now->modify('-60 days'),
            null,
            35000,
            $now->modify('+7 days'),
            $now->modify('-60 days'),
        );
        $contractTerminated = new Contract(
            Uuid::v7(),
            $orderTerminated,
            $terminatedTenant,
            $storageTerminated,
            RentalType::UNLIMITED,
            $now->modify('-60 days'),
            null,
            $now->modify('-60 days'),
        );
        $contractTerminated->terminate($now->modify('-1 day'), TerminationReason::TENANT_NOTICE);

        $this->entityManager->persist($activeTenant);
        $this->entityManager->persist($terminatedTenant);
        $this->entityManager->persist($neverRented);
        $this->entityManager->persist($place);
        $this->entityManager->persist($storageType);
        $this->entityManager->persist($storageActive);
        $this->entityManager->persist($storageTerminated);
        $this->entityManager->persist($orderActive);
        $this->entityManager->persist($orderTerminated);
        $this->entityManager->persist($contractActive);
        $this->entityManager->persist($contractTerminated);
        $this->entityManager->flush();

        $activeUsers = $this->repository->findWithActiveContractsPaginated(1, 100, $now);
        $activeEmails = array_map(static fn (User $u) => $u->email, $activeUsers);

        $this->assertContains('paginated-active@example.com', $activeEmails);
        $this->assertNotContains('paginated-terminated@example.com', $activeEmails);
        $this->assertNotContains('paginated-never@example.com', $activeEmails);
    }

    public function testCountWithActiveAndWithoutActiveContractsSumToCountTotal(): void
    {
        // Invariant: every user is either in the "with active contract" set
        // or the "without active contract" set — never both, never neither.
        // Fixture-loaded users plus a freshly inserted active/inactive pair
        // must always satisfy active + inactive == total, regardless of state.
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $activeTenant = new User(Uuid::v7(), 'invariant-active@example.com', 'password', 'Inv', 'Active', $now);
        $inactiveUser = new User(Uuid::v7(), 'invariant-inactive@example.com', 'password', 'Inv', 'Inactive', $now);

        $place = new Place(Uuid::v7(), 'Invariant Place', 'Address', 'City', '00000', null, $now);
        $storageType = new StorageType(Uuid::v7(), $place, 'Box', 100, 100, 100, 10000, 35000, 35000, 35000 * 12, $now);
        $storage = new Storage(
            Uuid::v7(),
            'IN-'.bin2hex(random_bytes(2)),
            ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            $storageType,
            $place,
            $now,
        );
        $order = new Order(
            Uuid::v7(),
            $activeTenant,
            $storage,
            RentalType::UNLIMITED,
            PaymentFrequency::MONTHLY,
            $now->modify('-30 days'),
            null,
            35000,
            $now->modify('+7 days'),
            $now->modify('-30 days'),
        );
        $contract = new Contract(
            Uuid::v7(),
            $order,
            $activeTenant,
            $storage,
            RentalType::UNLIMITED,
            $now->modify('-30 days'),
            null,
            $now->modify('-30 days'),
        );

        $this->entityManager->persist($activeTenant);
        $this->entityManager->persist($inactiveUser);
        $this->entityManager->persist($place);
        $this->entityManager->persist($storageType);
        $this->entityManager->persist($storage);
        $this->entityManager->persist($order);
        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        $total = $this->repository->countTotal();
        $active = $this->repository->countWithActiveContracts($now);
        $inactive = $this->repository->countWithoutActiveContracts($now);

        $this->assertSame($total, $active + $inactive);
    }

    public function testUpdateUser(): void
    {
        $now = new \DateTimeImmutable();
        $user = new User(Uuid::v7(), 'update@example.com', 'password123', 'Test', 'User', $now);
        $this->repository->save($user);
        $this->entityManager->flush();

        // Update user
        $user->markAsVerified($now);
        $this->repository->save($user);
        $this->entityManager->flush();

        // Fetch again and verify update
        $updatedUser = $this->repository->get($user->id);
        $this->assertTrue($updatedUser->isVerified());
    }
}
