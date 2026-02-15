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

    private function createStorage(?User $owner = null): Storage
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Test Storage Type',
            innerWidth: 100,
            innerHeight: 200,
            innerLength: 150,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: new \DateTimeImmutable(),
        );

        return new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 50, 'height' => 50, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
            owner: $owner,
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

    public function testLandlordCannotDeleteOwnStorage(): void
    {
        $landlord = $this->createUser('landlord@example.com');

        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storage = $this->createStorage($landlord);

        // Landlords cannot delete storages - admin only
        $result = $this->voter->vote($this->createToken($landlord), $storage, [StorageVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testLandlordCanEditPricesForOwnStorage(): void
    {
        $landlord = $this->createUser('landlord@example.com');

        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storage = $this->createStorage($landlord);

        $result = $this->voter->vote($this->createToken($landlord), $storage, [StorageVoter::EDIT_PRICES]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCanManagePhotosForOwnStorage(): void
    {
        $landlord = $this->createUser('landlord@example.com');

        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storage = $this->createStorage($landlord);

        $result = $this->voter->vote($this->createToken($landlord), $storage, [StorageVoter::MANAGE_PHOTOS]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCannotAssignOwnerForOwnStorage(): void
    {
        $landlord = $this->createUser('landlord@example.com');

        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $storage = $this->createStorage($landlord);

        // Landlords cannot assign/reassign owner - admin only
        $result = $this->voter->vote($this->createToken($landlord), $storage, [StorageVoter::ASSIGN_OWNER]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
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
