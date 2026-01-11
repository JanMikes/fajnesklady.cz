<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Enum\UserRole;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testCreateUser(): void
    {
        $email = 'test@example.com';
        $name = 'Test User';
        $password = 'hashedPassword123';

        $user = User::create($email, $name, $password);

        $this->assertSame($email, $user->getEmail());
        $this->assertSame($name, $user->getName());
        $this->assertSame($password, $user->getPassword());
        $this->assertInstanceOf(\Symfony\Component\Uid\Uuid::class, $user->getId());
        $this->assertFalse($user->isVerified());
        $this->assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $user->getUpdatedAt());
    }

    public function testUserIdentifierIsEmail(): void
    {
        $email = 'test@example.com';
        $user = User::create($email, 'Test User', 'password123');

        $this->assertSame($email, $user->getUserIdentifier());
    }

    public function testDefaultRoleIsRoleUser(): void
    {
        $user = User::create('test@example.com', 'Test User', 'password123');

        $roles = $user->getRoles();

        $this->assertContains(UserRole::USER->value, $roles);
        $this->assertCount(1, $roles);
    }

    public function testMarkAsVerified(): void
    {
        $user = User::create('test@example.com', 'Test User', 'password123');

        $this->assertFalse($user->isVerified());

        $originalUpdatedAt = $user->getUpdatedAt();
        sleep(1); // Ensure time difference
        $user->markAsVerified();

        $this->assertTrue($user->isVerified());
        $this->assertGreaterThan($originalUpdatedAt, $user->getUpdatedAt());
    }

    public function testChangePassword(): void
    {
        $user = User::create('test@example.com', 'Test User', 'oldPassword');

        $originalUpdatedAt = $user->getUpdatedAt();
        sleep(1); // Ensure time difference
        $newPassword = 'newHashedPassword';
        $user->changePassword($newPassword);

        $this->assertSame($newPassword, $user->getPassword());
        $this->assertGreaterThan($originalUpdatedAt, $user->getUpdatedAt());
    }

    public function testCreatedAtIsImmutable(): void
    {
        $user = User::create('test@example.com', 'Test User', 'password123');

        $createdAt = $user->getCreatedAt();

        // Perform operations that update updatedAt
        $user->markAsVerified();

        // CreatedAt should not change
        $this->assertSame($createdAt, $user->getCreatedAt());
    }
}
