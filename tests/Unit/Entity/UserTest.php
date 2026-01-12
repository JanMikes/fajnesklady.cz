<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use App\Enum\UserRole;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class UserTest extends TestCase
{
    public function testCreateUser(): void
    {
        $email = 'test@example.com';
        $firstName = 'Test';
        $lastName = 'User';
        $password = 'hashedPassword123';
        $now = new \DateTimeImmutable();

        $user = new User(Uuid::v7(), $email, $password, $firstName, $lastName, $now);

        $this->assertSame($email, $user->email);
        $this->assertSame($firstName, $user->firstName);
        $this->assertSame($lastName, $user->lastName);
        $this->assertSame('Test User', $user->fullName);
        $this->assertSame($password, $user->getPassword());
        $this->assertInstanceOf(Uuid::class, $user->id);
        $this->assertFalse($user->isVerified());
        $this->assertSame($now, $user->createdAt);
        $this->assertSame($now, $user->updatedAt);
    }

    public function testUserIdentifierIsEmail(): void
    {
        $email = 'test@example.com';
        $user = new User(Uuid::v7(), $email, 'password123', 'Test', 'User', new \DateTimeImmutable());

        $this->assertSame($email, $user->getUserIdentifier());
    }

    public function testDefaultRoleIsRoleUser(): void
    {
        $user = new User(Uuid::v7(), 'test@example.com', 'password123', 'Test', 'User', new \DateTimeImmutable());

        $roles = $user->getRoles();

        $this->assertContains(UserRole::USER->value, $roles);
        $this->assertCount(1, $roles);
    }

    public function testMarkAsVerified(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $verifiedAt = new \DateTimeImmutable('2024-01-01 11:00:00');
        $user = new User(Uuid::v7(), 'test@example.com', 'password123', 'Test', 'User', $createdAt);

        $this->assertFalse($user->isVerified());

        $originalUpdatedAt = $user->updatedAt;
        $user->markAsVerified($verifiedAt);

        $this->assertTrue($user->isVerified());
        $this->assertGreaterThan($originalUpdatedAt, $user->updatedAt);
        $this->assertSame($verifiedAt, $user->updatedAt);
    }

    public function testChangePassword(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $changedAt = new \DateTimeImmutable('2024-01-01 11:00:00');
        $user = new User(Uuid::v7(), 'test@example.com', 'oldPassword', 'Test', 'User', $createdAt);

        $originalUpdatedAt = $user->updatedAt;
        $newPassword = 'newHashedPassword';
        $user->changePassword($newPassword, $changedAt);

        $this->assertSame($newPassword, $user->getPassword());
        $this->assertGreaterThan($originalUpdatedAt, $user->updatedAt);
        $this->assertSame($changedAt, $user->updatedAt);
    }

    public function testCreatedAtIsImmutable(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $verifiedAt = new \DateTimeImmutable('2024-01-01 11:00:00');
        $user = new User(Uuid::v7(), 'test@example.com', 'password123', 'Test', 'User', $createdAt);

        // Perform operations that update updatedAt
        $user->markAsVerified($verifiedAt);

        // CreatedAt should not change
        $this->assertSame($createdAt, $user->createdAt);
    }

    public function testFullNamePropertyHook(): void
    {
        $user = new User(Uuid::v7(), 'test@example.com', 'password123', 'Jan', 'Novak', new \DateTimeImmutable());

        $this->assertSame('Jan Novak', $user->fullName);
    }

    public function testUpdateProfile(): void
    {
        $createdAt = new \DateTimeImmutable('2024-01-01 10:00:00');
        $updatedAt = new \DateTimeImmutable('2024-01-01 11:00:00');
        $user = new User(Uuid::v7(), 'test@example.com', 'password123', 'Test', 'User', $createdAt);

        $user->updateProfile('New', 'Name', '+420123456789', $updatedAt);

        $this->assertSame('New', $user->firstName);
        $this->assertSame('Name', $user->lastName);
        $this->assertSame('New Name', $user->fullName);
        $this->assertSame('+420123456789', $user->phone);
        $this->assertSame($updatedAt, $user->updatedAt);
    }

    public function testUserCanBeCreatedWithoutPassword(): void
    {
        $user = new User(
            id: Uuid::v7(),
            email: 'passwordless@example.com',
            password: null,
            firstName: 'Test',
            lastName: 'User',
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertNull($user->getPassword());
        $this->assertFalse($user->hasPassword());
    }

    public function testUserWithPasswordReportsHasPassword(): void
    {
        $user = new User(
            id: Uuid::v7(),
            email: 'test@example.com',
            password: 'hashedpassword',
            firstName: 'Test',
            lastName: 'User',
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertTrue($user->hasPassword());
    }

    public function testPasswordCanBeSetOnPasswordlessUser(): void
    {
        $user = new User(
            id: Uuid::v7(),
            email: 'passwordless@example.com',
            password: null,
            firstName: 'Test',
            lastName: 'User',
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertFalse($user->hasPassword());

        $user->changePassword('newhashedpassword', new \DateTimeImmutable());

        $this->assertSame('newhashedpassword', $user->getPassword());
        $this->assertTrue($user->hasPassword());
    }

    public function testEmptyPasswordIsConsideredNoPassword(): void
    {
        $user = new User(
            id: Uuid::v7(),
            email: 'test@example.com',
            password: '',
            firstName: 'Test',
            lastName: 'User',
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertFalse($user->hasPassword());
    }

    public function testChangeRole(): void
    {
        $user = new User(
            id: Uuid::v7(),
            email: 'test@example.com',
            password: null,
            firstName: 'Test',
            lastName: 'User',
            createdAt: new \DateTimeImmutable(),
        );

        $user->changeRole(UserRole::LANDLORD, new \DateTimeImmutable());

        $this->assertContains('ROLE_USER', $user->getRoles());
        $this->assertContains('ROLE_LANDLORD', $user->getRoles());
    }
}
