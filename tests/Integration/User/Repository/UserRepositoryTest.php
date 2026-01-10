<?php

declare(strict_types=1);

namespace App\Tests\Integration\User\Repository;

use App\User\Entity\User;
use App\User\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class UserRepositoryTest extends KernelTestCase
{
    private UserRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(UserRepository::class);
    }

    public function testSaveUser(): void
    {
        $user = User::create('test@example.com', 'Test User', 'password123');

        $this->repository->save($user);

        $foundUser = $this->repository->findById($user->getId());
        $this->assertNotNull($foundUser);
        $this->assertSame($user->getEmail(), $foundUser->getEmail());
        $this->assertSame($user->getName(), $foundUser->getName());
    }

    public function testFindById(): void
    {
        $user = User::create('findbyid@example.com', 'Test User', 'password123');
        $this->repository->save($user);

        $foundUser = $this->repository->findById($user->getId());

        $this->assertNotNull($foundUser);
        $this->assertEquals($user->getId(), $foundUser->getId());
    }

    public function testFindByIdReturnsNullForNonexistent(): void
    {
        $nonexistentId = \Symfony\Component\Uid\Uuid::v7();

        $foundUser = $this->repository->findById($nonexistentId);

        $this->assertNull($foundUser);
    }

    public function testFindByEmail(): void
    {
        $email = 'findbyemail@example.com';
        $user = User::create($email, 'Test User', 'password123');
        $this->repository->save($user);

        $foundUser = $this->repository->findByEmail($email);

        $this->assertNotNull($foundUser);
        $this->assertSame($email, $foundUser->getEmail());
    }

    public function testFindByEmailReturnsNullForNonexistent(): void
    {
        $foundUser = $this->repository->findByEmail('nonexistent@example.com');

        $this->assertNull($foundUser);
    }

    public function testFindAll(): void
    {
        $initialCount = count($this->repository->findAll());

        // Create multiple users
        $user1 = User::create('user1@example.com', 'User 1', 'password123');
        $user2 = User::create('user2@example.com', 'User 2', 'password123');
        $user3 = User::create('user3@example.com', 'User 3', 'password123');

        $this->repository->save($user1);
        $this->repository->save($user2);
        $this->repository->save($user3);

        $users = $this->repository->findAll();

        $this->assertCount($initialCount + 3, $users);
        // Verify all our newly created users are returned
        $emails = array_map(fn (User $u) => $u->getEmail(), $users);
        $this->assertContains('user1@example.com', $emails);
        $this->assertContains('user2@example.com', $emails);
        $this->assertContains('user3@example.com', $emails);
    }

    public function testFindAllPaginated(): void
    {
        $initialCount = count($this->repository->findAll());

        // Create 5 users
        for ($i = 1; $i <= 5; ++$i) {
            $user = User::create("paginated{$i}@example.com", "User {$i}", 'password123');
            $this->repository->save($user);
        }

        $totalCount = $initialCount + 5;
        $limit = 2;

        // Get page 1 with limit 2
        $page1Users = $this->repository->findAllPaginated(1, $limit);
        $this->assertCount($limit, $page1Users);

        // Get page 2 with limit 2
        $page2Users = $this->repository->findAllPaginated(2, $limit);
        $this->assertCount($limit, $page2Users);

        // Verify users are different between pages
        $this->assertNotEquals($page1Users[0]->getId(), $page2Users[0]->getId());

        // Get last page - calculate expected count
        $lastPage = (int) ceil($totalCount / $limit);
        $lastPageUsers = $this->repository->findAllPaginated($lastPage, $limit);
        $expectedLastPageCount = $totalCount % $limit ?: $limit;
        $this->assertCount($expectedLastPageCount, $lastPageUsers);
    }

    public function testFindAllPaginatedOrderedByCreatedAtDesc(): void
    {
        // Get initial users from fixtures (created during bootstrap)
        $fixtureUsers = $this->repository->findAll();
        $fixtureEmails = array_map(fn (User $u) => $u->getEmail(), $fixtureUsers);

        // Create 3 new users - these will be more recently created than fixtures
        $user1 = User::create('order1@example.com', 'User 1', 'password123');
        $user2 = User::create('order2@example.com', 'User 2', 'password123');
        $user3 = User::create('order3@example.com', 'User 3', 'password123');

        $this->repository->save($user1);
        $this->repository->save($user2);
        $this->repository->save($user3);

        $users = $this->repository->findAllPaginated(1, 10);

        // Newly created users should appear before fixture users
        // First 3 results should be our new users (in some order - timestamps may be identical)
        $firstThreeEmails = array_map(fn (User $u) => $u->getEmail(), array_slice($users, 0, 3));
        $this->assertContains('order1@example.com', $firstThreeEmails);
        $this->assertContains('order2@example.com', $firstThreeEmails);
        $this->assertContains('order3@example.com', $firstThreeEmails);

        // Remaining users should be the fixture users
        $remainingEmails = array_map(fn (User $u) => $u->getEmail(), array_slice($users, 3));
        foreach ($remainingEmails as $email) {
            $this->assertContains($email, $fixtureEmails);
        }
    }

    public function testUpdateUser(): void
    {
        $user = User::create('update@example.com', 'Test User', 'password123');
        $this->repository->save($user);

        // Update user
        $user->markAsVerified();
        $this->repository->save($user);

        // Fetch again and verify update
        $updatedUser = $this->repository->findById($user->getId());
        $this->assertTrue($updatedUser->isVerified());
    }
}
