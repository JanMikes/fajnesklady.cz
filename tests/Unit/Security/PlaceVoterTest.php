<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Place;
use App\Entity\User;
use App\Security\PlaceVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

class PlaceVoterTest extends TestCase
{
    private PlaceVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new PlaceVoter();
    }

    private function createUser(string $email = 'user@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'password', 'Test User', new \DateTimeImmutable());
    }

    private function createPlace(User $owner): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            description: null,
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

    public function testAdminCanViewAnyPlace(): void
    {
        $owner = $this->createUser('owner@example.com');
        $admin = $this->createUser('admin@example.com');

        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $place = $this->createPlace($owner);

        $result = $this->voter->vote($this->createToken($admin), $place, [PlaceVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanEditAnyPlace(): void
    {
        $owner = $this->createUser('owner@example.com');
        $admin = $this->createUser('admin@example.com');

        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $place = $this->createPlace($owner);

        $result = $this->voter->vote($this->createToken($admin), $place, [PlaceVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanDeleteAnyPlace(): void
    {
        $owner = $this->createUser('owner@example.com');
        $admin = $this->createUser('admin@example.com');

        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $place = $this->createPlace($owner);

        $result = $this->voter->vote($this->createToken($admin), $place, [PlaceVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCanViewOwnPlace(): void
    {
        $landlord = $this->createUser('landlord@example.com');

        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $place = $this->createPlace($landlord);

        $result = $this->voter->vote($this->createToken($landlord), $place, [PlaceVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCanEditOwnPlace(): void
    {
        $landlord = $this->createUser('landlord@example.com');

        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $place = $this->createPlace($landlord);

        $result = $this->voter->vote($this->createToken($landlord), $place, [PlaceVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCanDeleteOwnPlace(): void
    {
        $landlord = $this->createUser('landlord@example.com');

        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $place = $this->createPlace($landlord);

        $result = $this->voter->vote($this->createToken($landlord), $place, [PlaceVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCannotViewOtherLandlordPlace(): void
    {
        $owner = $this->createUser('owner@example.com');
        $otherLandlord = $this->createUser('other@example.com');

        $this->setUserRoles($otherLandlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $place = $this->createPlace($owner);

        $result = $this->voter->vote($this->createToken($otherLandlord), $place, [PlaceVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testLandlordCannotEditOtherLandlordPlace(): void
    {
        $owner = $this->createUser('owner@example.com');
        $otherLandlord = $this->createUser('other@example.com');

        $this->setUserRoles($otherLandlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $place = $this->createPlace($owner);

        $result = $this->voter->vote($this->createToken($otherLandlord), $place, [PlaceVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testLandlordCannotDeleteOtherLandlordPlace(): void
    {
        $owner = $this->createUser('owner@example.com');
        $otherLandlord = $this->createUser('other@example.com');

        $this->setUserRoles($otherLandlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $place = $this->createPlace($owner);

        $result = $this->voter->vote($this->createToken($otherLandlord), $place, [PlaceVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testRegularUserCannotAccessPlace(): void
    {
        $owner = $this->createUser('owner@example.com');
        $regularUser = $this->createUser('user@example.com');

        $place = $this->createPlace($owner);

        $result = $this->voter->vote($this->createToken($regularUser), $place, [PlaceVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAnonymousUserCannotAccessPlace(): void
    {
        $owner = $this->createUser('owner@example.com');
        $place = $this->createPlace($owner);

        $result = $this->voter->vote($this->createToken(null), $place, [PlaceVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        $owner = $this->createUser('owner@example.com');
        $place = $this->createPlace($owner);

        $result = $this->voter->vote($this->createToken($owner), $place, ['UNSUPPORTED_ATTRIBUTE']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAbstainsForNonPlaceSubject(): void
    {
        $user = $this->createUser();

        $result = $this->voter->vote($this->createToken($user), new \stdClass(), [PlaceVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
