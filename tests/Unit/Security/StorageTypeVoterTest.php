<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\StorageType;
use App\Entity\User;
use App\Service\Security\StorageTypeVoter;
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
        return new User(Uuid::v7(), $email, 'password', 'Test', 'User', new \DateTimeImmutable());
    }

    private function createStorageType(): StorageType
    {
        return new StorageType(
            id: Uuid::v7(),
            name: 'Test Storage Type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 30000,
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

    public function testAdminCanViewStorageType(): void
    {
        $admin = $this->createUser('admin@example.com');
        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $storageType = $this->createStorageType();

        $result = $this->voter->vote($this->createToken($admin), $storageType, [StorageTypeVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanEditStorageType(): void
    {
        $admin = $this->createUser('admin@example.com');
        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $storageType = $this->createStorageType();

        $result = $this->voter->vote($this->createToken($admin), $storageType, [StorageTypeVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanDeleteStorageType(): void
    {
        $admin = $this->createUser('admin@example.com');
        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $storageType = $this->createStorageType();

        $result = $this->voter->vote($this->createToken($admin), $storageType, [StorageTypeVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCanViewStorageType(): void
    {
        $landlord = $this->createUser('landlord@example.com');
        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storageType = $this->createStorageType();

        $result = $this->voter->vote($this->createToken($landlord), $storageType, [StorageTypeVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCannotEditStorageType(): void
    {
        $landlord = $this->createUser('landlord@example.com');
        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storageType = $this->createStorageType();

        $result = $this->voter->vote($this->createToken($landlord), $storageType, [StorageTypeVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testLandlordCannotDeleteStorageType(): void
    {
        $landlord = $this->createUser('landlord@example.com');
        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storageType = $this->createStorageType();

        $result = $this->voter->vote($this->createToken($landlord), $storageType, [StorageTypeVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testRegularUserCannotAccessStorageType(): void
    {
        $regularUser = $this->createUser('user@example.com');

        $storageType = $this->createStorageType();

        $result = $this->voter->vote($this->createToken($regularUser), $storageType, [StorageTypeVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAnonymousUserCannotAccessStorageType(): void
    {
        $storageType = $this->createStorageType();

        $result = $this->voter->vote($this->createToken(null), $storageType, [StorageTypeVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        $user = $this->createUser();
        $storageType = $this->createStorageType();

        $result = $this->voter->vote($this->createToken($user), $storageType, ['UNSUPPORTED_ATTRIBUTE']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAbstainsForNonStorageTypeSubject(): void
    {
        $user = $this->createUser();

        $result = $this->voter->vote($this->createToken($user), new \stdClass(), [StorageTypeVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
