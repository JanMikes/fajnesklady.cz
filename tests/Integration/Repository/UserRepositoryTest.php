<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\User;
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
