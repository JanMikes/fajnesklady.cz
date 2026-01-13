<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\UserRole;
use PHPUnit\Framework\TestCase;

class UserRoleTest extends TestCase
{
    public function testIsValidReturnsTrueForRoleUser(): void
    {
        $this->assertTrue(UserRole::isValid('ROLE_USER'));
    }

    public function testIsValidReturnsTrueForRoleLandlord(): void
    {
        $this->assertTrue(UserRole::isValid('ROLE_LANDLORD'));
    }

    public function testIsValidReturnsTrueForRoleAdmin(): void
    {
        $this->assertTrue(UserRole::isValid('ROLE_ADMIN'));
    }

    public function testIsValidReturnsFalseForInvalidRole(): void
    {
        $this->assertFalse(UserRole::isValid('INVALID_ROLE'));
    }

    public function testIsValidReturnsFalseForEmptyString(): void
    {
        $this->assertFalse(UserRole::isValid(''));
    }

    public function testIsValidReturnsFalseForLowerCase(): void
    {
        $this->assertFalse(UserRole::isValid('role_user'));
    }

    public function testValuesReturnsAllRoles(): void
    {
        $values = UserRole::values();

        $this->assertCount(3, $values);
        $this->assertContains('ROLE_USER', $values);
        $this->assertContains('ROLE_LANDLORD', $values);
        $this->assertContains('ROLE_ADMIN', $values);
    }

    public function testLabelReturnsCorrectLabelForUser(): void
    {
        $this->assertSame('Uživatel', UserRole::USER->label());
    }

    public function testLabelReturnsCorrectLabelForLandlord(): void
    {
        $this->assertSame('Pronajímatel', UserRole::LANDLORD->label());
    }

    public function testLabelReturnsCorrectLabelForAdmin(): void
    {
        $this->assertSame('Administrátor', UserRole::ADMIN->label());
    }
}
