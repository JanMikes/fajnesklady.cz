<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Entity;

use App\User\Entity\User;
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

        $this->assertContains('ROLE_USER', $roles);
        $this->assertCount(1, $roles);
    }

    public function testGetRolesAlwaysIncludesRoleUser(): void
    {
        $user = User::create('test@example.com', 'Test User', 'password123');
        $user->changeRole('ROLE_ADMIN');

        $roles = $user->getRoles();

        $this->assertContains('ROLE_USER', $roles);
        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertCount(2, $roles);
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

    public function testChangeRole(): void
    {
        $user = User::create('test@example.com', 'Test User', 'password123');

        $originalUpdatedAt = $user->getUpdatedAt();
        sleep(1); // Ensure time difference
        $user->changeRole('ROLE_ADMIN');

        $roles = $user->getRoles();
        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_USER', $roles); // Still includes ROLE_USER
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

    public function testSetPassword(): void
    {
        $user = User::create('test@example.com', 'Test User', 'oldPassword');

        $originalUpdatedAt = $user->getUpdatedAt();
        sleep(1); // Ensure time difference
        $newPassword = 'newHashedPassword';
        $user->setPassword($newPassword);

        $this->assertSame($newPassword, $user->getPassword());
        $this->assertGreaterThan($originalUpdatedAt, $user->getUpdatedAt());
    }

    public function testEraseCredentialsDoesNothing(): void
    {
        $user = User::create('test@example.com', 'Test User', 'password123');
        $password = $user->getPassword();

        $user->eraseCredentials();

        // Password should remain unchanged
        $this->assertSame($password, $user->getPassword());
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
