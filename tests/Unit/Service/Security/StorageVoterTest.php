<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Security;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Service\Security\StorageVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

class StorageVoterTest extends TestCase
{
    private StorageVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new StorageVoter();
    }

    private function createUser(string $email = 'user@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'password', 'Test', 'User', new \DateTimeImmutable());
    }

    private function createStorage(User $owner): Storage
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: new \DateTimeImmutable(),
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test Storage Type',
            width: 100,
            height: 200,
            length: 150,
            pricePerWeek: 10000,
            pricePerMonth: 35000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        return new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 50, 'height' => 50, 'rotation' => 0],
            storageType: $storageType,
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

    public function testAdminCanViewAnyStorage(): void
    {
        $owner = $this->createUser('owner@example.com');
        $admin = $this->createUser('admin@example.com');

        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $storage = $this->createStorage($owner);

        $result = $this->voter->vote($this->createToken($admin), $storage, [StorageVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanEditAnyStorage(): void
    {
        $owner = $this->createUser('owner@example.com');
        $admin = $this->createUser('admin@example.com');

        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $storage = $this->createStorage($owner);

        $result = $this->voter->vote($this->createToken($admin), $storage, [StorageVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanDeleteAnyStorage(): void
    {
        $owner = $this->createUser('owner@example.com');
        $admin = $this->createUser('admin@example.com');

        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $storage = $this->createStorage($owner);

        $result = $this->voter->vote($this->createToken($admin), $storage, [StorageVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCanViewOwnStorage(): void
    {
        $landlord = $this->createUser('landlord@example.com');

        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storage = $this->createStorage($landlord);

        $result = $this->voter->vote($this->createToken($landlord), $storage, [StorageVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCanEditOwnStorage(): void
    {
        $landlord = $this->createUser('landlord@example.com');

        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storage = $this->createStorage($landlord);

        $result = $this->voter->vote($this->createToken($landlord), $storage, [StorageVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCanDeleteOwnStorage(): void
    {
        $landlord = $this->createUser('landlord@example.com');

        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storage = $this->createStorage($landlord);

        $result = $this->voter->vote($this->createToken($landlord), $storage, [StorageVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCannotViewOtherLandlordStorage(): void
    {
        $owner = $this->createUser('owner@example.com');
        $otherLandlord = $this->createUser('other@example.com');

        $this->setUserRoles($otherLandlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storage = $this->createStorage($owner);

        $result = $this->voter->vote($this->createToken($otherLandlord), $storage, [StorageVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testLandlordCannotEditOtherLandlordStorage(): void
    {
        $owner = $this->createUser('owner@example.com');
        $otherLandlord = $this->createUser('other@example.com');

        $this->setUserRoles($otherLandlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storage = $this->createStorage($owner);

        $result = $this->voter->vote($this->createToken($otherLandlord), $storage, [StorageVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testLandlordCannotDeleteOtherLandlordStorage(): void
    {
        $owner = $this->createUser('owner@example.com');
        $otherLandlord = $this->createUser('other@example.com');

        $this->setUserRoles($otherLandlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storage = $this->createStorage($owner);

        $result = $this->voter->vote($this->createToken($otherLandlord), $storage, [StorageVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testRegularUserCannotAccessStorage(): void
    {
        $owner = $this->createUser('owner@example.com');
        $regularUser = $this->createUser('user@example.com');

        $storage = $this->createStorage($owner);

        $result = $this->voter->vote($this->createToken($regularUser), $storage, [StorageVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAnonymousUserCannotAccessStorage(): void
    {
        $owner = $this->createUser('owner@example.com');
        $storage = $this->createStorage($owner);

        $result = $this->voter->vote($this->createToken(null), $storage, [StorageVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        $owner = $this->createUser('owner@example.com');
        $storage = $this->createStorage($owner);

        $result = $this->voter->vote($this->createToken($owner), $storage, ['UNSUPPORTED_ATTRIBUTE']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAbstainsForNonStorageSubject(): void
    {
        $user = $this->createUser();

        $result = $this->voter->vote($this->createToken($user), new \stdClass(), [StorageVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
