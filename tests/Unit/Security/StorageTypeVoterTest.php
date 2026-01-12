<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\StorageType;
use App\Entity\User;
use App\Security\StorageTypeVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

class StorageTypeVoterTest extends TestCase
{
    private StorageTypeVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new StorageTypeVoter();
    }

    private function createUser(string $email = 'user@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'password', 'Test User', new \DateTimeImmutable());
    }

    private function createStorageType(User $owner): StorageType
    {
        return new StorageType(
            id: Uuid::v7(),
            name: 'Test Storage Type',
            width: '1.0',
            height: '1.0',
            length: '1.0',
            pricePerWeek: 10000,
            pricePerMonth: 30000,
            owner: $owner,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createToken(?User $user): TokenInterface
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        return $token;
    }

    /**
     * @param string[] $roles
     */
    private function setUserRoles(User $user, array $roles): void
    {
        $reflection = new \ReflectionClass($user);
        $rolesProperty = $reflection->getProperty('roles');
        $rolesProperty->setValue($user, $roles);
    }

    public function testAdminCanViewAnyStorageType(): void
    {
        $owner = $this->createUser('owner@example.com');
        $admin = $this->createUser('admin@example.com');

        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $storageType = $this->createStorageType($owner);

        $result = $this->voter->vote($this->createToken($admin), $storageType, [StorageTypeVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanEditAnyStorageType(): void
    {
        $owner = $this->createUser('owner@example.com');
        $admin = $this->createUser('admin@example.com');

        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $storageType = $this->createStorageType($owner);

        $result = $this->voter->vote($this->createToken($admin), $storageType, [StorageTypeVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanDeleteAnyStorageType(): void
    {
        $owner = $this->createUser('owner@example.com');
        $admin = $this->createUser('admin@example.com');

        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $storageType = $this->createStorageType($owner);

        $result = $this->voter->vote($this->createToken($admin), $storageType, [StorageTypeVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCanViewOwnStorageType(): void
    {
        $landlord = $this->createUser('landlord@example.com');

        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storageType = $this->createStorageType($landlord);

        $result = $this->voter->vote($this->createToken($landlord), $storageType, [StorageTypeVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCanEditOwnStorageType(): void
    {
        $landlord = $this->createUser('landlord@example.com');

        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storageType = $this->createStorageType($landlord);

        $result = $this->voter->vote($this->createToken($landlord), $storageType, [StorageTypeVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCanDeleteOwnStorageType(): void
    {
        $landlord = $this->createUser('landlord@example.com');

        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storageType = $this->createStorageType($landlord);

        $result = $this->voter->vote($this->createToken($landlord), $storageType, [StorageTypeVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCannotViewOtherLandlordStorageType(): void
    {
        $owner = $this->createUser('owner@example.com');
        $otherLandlord = $this->createUser('other@example.com');

        $this->setUserRoles($otherLandlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storageType = $this->createStorageType($owner);

        $result = $this->voter->vote($this->createToken($otherLandlord), $storageType, [StorageTypeVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testLandlordCannotEditOtherLandlordStorageType(): void
    {
        $owner = $this->createUser('owner@example.com');
        $otherLandlord = $this->createUser('other@example.com');

        $this->setUserRoles($otherLandlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storageType = $this->createStorageType($owner);

        $result = $this->voter->vote($this->createToken($otherLandlord), $storageType, [StorageTypeVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testLandlordCannotDeleteOtherLandlordStorageType(): void
    {
        $owner = $this->createUser('owner@example.com');
        $otherLandlord = $this->createUser('other@example.com');

        $this->setUserRoles($otherLandlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storageType = $this->createStorageType($owner);

        $result = $this->voter->vote($this->createToken($otherLandlord), $storageType, [StorageTypeVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testRegularUserCannotAccessStorageType(): void
    {
        $owner = $this->createUser('owner@example.com');
        $regularUser = $this->createUser('user@example.com');

        $storageType = $this->createStorageType($owner);

        $result = $this->voter->vote($this->createToken($regularUser), $storageType, [StorageTypeVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAnonymousUserCannotAccessStorageType(): void
    {
        $owner = $this->createUser('owner@example.com');
        $storageType = $this->createStorageType($owner);

        $result = $this->voter->vote($this->createToken(null), $storageType, [StorageTypeVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        $owner = $this->createUser('owner@example.com');
        $storageType = $this->createStorageType($owner);

        $result = $this->voter->vote($this->createToken($owner), $storageType, ['UNSUPPORTED_ATTRIBUTE']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAbstainsForNonStorageTypeSubject(): void
    {
        $user = $this->createUser();

        $result = $this->voter->vote($this->createToken($user), new \stdClass(), [StorageTypeVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
