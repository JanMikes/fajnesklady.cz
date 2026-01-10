<?php

declare(strict_types=1);

namespace App\Tests\Unit\User\Entity;

use App\User\Entity\User;
use App\User\Enum\UserRole;
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

    public function testRecordFailedLoginAttempt(): void
    {
        $user = User::create('test@example.com', 'Test User', 'password123');

        $this->assertSame(0, $user->getFailedLoginAttempts());
        $this->assertFalse($user->isLocked());

        $user->recordFailedLoginAttempt();
        $this->assertSame(1, $user->getFailedLoginAttempts());
        $this->assertFalse($user->isLocked());

        // Record 4 more attempts to trigger lock
        for ($i = 0; $i < 4; ++$i) {
            $user->recordFailedLoginAttempt();
        }

        $this->assertSame(5, $user->getFailedLoginAttempts());
        $this->assertTrue($user->isLocked());
        $this->assertNotNull($user->getLockedUntil());
    }

    public function testResetFailedLoginAttempts(): void
    {
        $user = User::create('test@example.com', 'Test User', 'password123');

        // Lock the user
        for ($i = 0; $i < 5; ++$i) {
            $user->recordFailedLoginAttempt();
        }

        $this->assertTrue($user->isLocked());

        $user->resetFailedLoginAttempts();

        $this->assertSame(0, $user->getFailedLoginAttempts());
        $this->assertNull($user->getLockedUntil());
        $this->assertFalse($user->isLocked());
    }

    public function testIsLockedDoesNotMutateState(): void
    {
        $user = User::create('test@example.com', 'Test User', 'password123');

        // Lock the user
        for ($i = 0; $i < 5; ++$i) {
            $user->recordFailedLoginAttempt();
        }

        $this->assertTrue($user->isLocked());
        $failedAttemptsBefore = $user->getFailedLoginAttempts();
        $lockedUntilBefore = $user->getLockedUntil();

        // Calling isLocked() should NOT mutate state
        $user->isLocked();
        $user->isLocked();
        $user->isLocked();

        $this->assertSame($failedAttemptsBefore, $user->getFailedLoginAttempts());
        $this->assertSame($lockedUntilBefore, $user->getLockedUntil());
    }

    public function testIsLockExpired(): void
    {
        $user = User::create('test@example.com', 'Test User', 'password123');

        // Not locked, not expired
        $this->assertFalse($user->isLockExpired());

        // Lock the user
        for ($i = 0; $i < 5; ++$i) {
            $user->recordFailedLoginAttempt();
        }

        // Locked but not expired (lock is in the future)
        $this->assertTrue($user->isLocked());
        $this->assertFalse($user->isLockExpired());
    }

    public function testNewUserIsNotLocked(): void
    {
        $user = User::create('test@example.com', 'Test User', 'password123');

        $this->assertFalse($user->isLocked());
        $this->assertFalse($user->isLockExpired());
        $this->assertSame(0, $user->getFailedLoginAttempts());
        $this->assertNull($user->getLockedUntil());
    }
}
