<?php

declare(strict_types=1);

namespace App\Tests\Integration\User\Repository;

use App\User\Entity\User;
use App\User\Repository\UserRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class UserRepositoryTest extends KernelTestCase
{
    private UserRepositoryInterface $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->repository = $container->get(UserRepositoryInterface::class);
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
        // Create multiple users
        $user1 = User::create('user1@example.com', 'User 1', 'password123');
        $user2 = User::create('user2@example.com', 'User 2', 'password123');
        $user3 = User::create('user3@example.com', 'User 3', 'password123');

        $this->repository->save($user1);
        $this->repository->save($user2);
        $this->repository->save($user3);

        $users = $this->repository->findAll();

        $this->assertCount(3, $users);
        // Should be ordered by createdAt DESC, so user3 should be first
        $this->assertSame('user3@example.com', $users[0]->getEmail());
    }

    public function testFindAllPaginated(): void
    {
        // Create 5 users
        for ($i = 1; $i <= 5; ++$i) {
            $user = User::create("paginated{$i}@example.com", "User {$i}", 'password123');
            $this->repository->save($user);
        }

        // Get page 1 with limit 2
        $page1Users = $this->repository->findAllPaginated(1, 2);
        $this->assertCount(2, $page1Users);

        // Get page 2 with limit 2
        $page2Users = $this->repository->findAllPaginated(2, 2);
        $this->assertCount(2, $page2Users);

        // Get page 3 with limit 2 (should have only 1 user)
        $page3Users = $this->repository->findAllPaginated(3, 2);
        $this->assertCount(1, $page3Users);

        // Verify users are different between pages
        $this->assertNotEquals($page1Users[0]->getId(), $page2Users[0]->getId());
    }

    public function testFindAllPaginatedOrderedByCreatedAtDesc(): void
    {
        // Create 3 users
        $user1 = User::create('order1@example.com', 'User 1', 'password123');
        $user2 = User::create('order2@example.com', 'User 2', 'password123');
        $user3 = User::create('order3@example.com', 'User 3', 'password123');

        $this->repository->save($user1);
        $this->repository->save($user2);
        $this->repository->save($user3);

        $users = $this->repository->findAllPaginated(1, 10);

        // Most recently created should be first
        $this->assertSame('order3@example.com', $users[0]->getEmail());
        $this->assertSame('order2@example.com', $users[1]->getEmail());
        $this->assertSame('order1@example.com', $users[2]->getEmail());
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
