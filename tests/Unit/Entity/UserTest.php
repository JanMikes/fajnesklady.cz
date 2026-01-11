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
        $now = new \DateTimeImmutable();

        $user = User::create($email, $name, $password, $now);

        $this->assertSame($email, $user->getEmail());
        $this->assertSame($name, $user->getName());
        $this->assertSame($password, $user->getPassword());
        $this->assertInstanceOf(\Symfony\Component\Uid\Uuid::class, $user->getId());
        $this->assertFalse($user->isVerified());
        $this->assertSame($now, $user->getCreatedAt());
        $this->assertSame($now, $user->getUpdatedAt());
    }

    public function testUserIdentifierIsEmail(): void
    {
        $email = 'test@example.com';
        $user = User::create($email, 'Test User', 'password123', new \DateTimeImmutable());

        $this->assertSame($email, $user->getUserIdentifier());
    }

    public function testDefaultRoleIsRoleUser(): void
    {
        $user = User::create('test@example.com', 'Test User', 'password123', new \DateTimeImmutable());

        $roles = $user->getRoles();

        $this->assertContains(UserRole::USER->value, $roles);
        $this->assertCount(1, $roles);
    }

    public function testMarkAsVerified(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $verifiedAt = new \DateTimeImmutable('2024-01-01 11:00:00');
        $user = User::create('test@example.com', 'Test User', 'password123', $createdAt);

        $this->assertFalse($user->isVerified());

        $originalUpdatedAt = $user->getUpdatedAt();
        $user->markAsVerified($verifiedAt);

        $this->assertTrue($user->isVerified());
        $this->assertGreaterThan($originalUpdatedAt, $user->getUpdatedAt());
        $this->assertSame($verifiedAt, $user->getUpdatedAt());
    }

    public function testChangePassword(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $changedAt = new \DateTimeImmutable('2024-01-01 11:00:00');
        $user = User::create('test@example.com', 'Test User', 'oldPassword', $createdAt);

        $originalUpdatedAt = $user->getUpdatedAt();
        $newPassword = 'newHashedPassword';
        $user->changePassword($newPassword, $changedAt);

        $this->assertSame($newPassword, $user->getPassword());
        $this->assertGreaterThan($originalUpdatedAt, $user->getUpdatedAt());
        $this->assertSame($changedAt, $user->getUpdatedAt());
    }

    public function testCreatedAtIsImmutable(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $verifiedAt = new \DateTimeImmutable('2024-01-01 11:00:00');
        $user = User::create('test@example.com', 'Test User', 'password123', $createdAt);

        // Perform operations that update updatedAt
        $user->markAsVerified($verifiedAt);

        // CreatedAt should not change
        $this->assertSame($createdAt, $user->getCreatedAt());
    }
}
