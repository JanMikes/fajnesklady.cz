<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Place;
use App\Entity\User;
use App\Repository\PlaceAccessRepository;
use App\Service\Security\PlaceVoter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

class PlaceVoterTest extends TestCase
{
    private PlaceAccessRepository&MockObject $placeAccessRepository;
    private PlaceVoter $voter;

    protected function setUp(): void
    {
        $this->placeAccessRepository = $this->createMock(PlaceAccessRepository::class);
        $this->voter = new PlaceVoter($this->placeAccessRepository);
    }

    private function createUser(string $email = 'user@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'password', 'Test', 'User', new \DateTimeImmutable());
    }

    private function createPlace(): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
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
        $admin = $this->createUser('admin@example.com');
        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $place = $this->createPlace();

        $result = $this->voter->vote($this->createToken($admin), $place, [PlaceVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanEditAnyPlace(): void
    {
        $admin = $this->createUser('admin@example.com');
        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $place = $this->createPlace();

        $result = $this->voter->vote($this->createToken($admin), $place, [PlaceVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testAdminCanDeleteAnyPlace(): void
    {
        $admin = $this->createUser('admin@example.com');
        $this->setUserRoles($admin, ['ROLE_USER', 'ROLE_ADMIN']);

        $place = $this->createPlace();

        $result = $this->voter->vote($this->createToken($admin), $place, [PlaceVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCanViewAnyPlace(): void
    {
        $landlord = $this->createUser('landlord@example.com');
        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $place = $this->createPlace();

        $result = $this->voter->vote($this->createToken($landlord), $place, [PlaceVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordCannotEditPlace(): void
    {
        $landlord = $this->createUser('landlord@example.com');
        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $place = $this->createPlace();

        $result = $this->voter->vote($this->createToken($landlord), $place, [PlaceVoter::EDIT]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testLandlordCannotDeletePlace(): void
    {
        $landlord = $this->createUser('landlord@example.com');
        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $place = $this->createPlace();

        $result = $this->voter->vote($this->createToken($landlord), $place, [PlaceVoter::DELETE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testLandlordWithAccessCanRequestChange(): void
    {
        $landlord = $this->createUser('landlord@example.com');
        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $place = $this->createPlace();

        $this->placeAccessRepository
            ->expects($this->once())
            ->method('hasAccess')
            ->with($landlord, $place)
            ->willReturn(true);

        $result = $this->voter->vote($this->createToken($landlord), $place, [PlaceVoter::REQUEST_CHANGE]);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testLandlordWithoutAccessCannotRequestChange(): void
    {
        $landlord = $this->createUser('landlord@example.com');
        $this->setUserRoles($landlord, ['ROLE_USER', 'ROLE_LANDLORD']);

        $place = $this->createPlace();

        $this->placeAccessRepository
            ->expects($this->once())
            ->method('hasAccess')
            ->with($landlord, $place)
            ->willReturn(false);

        $result = $this->voter->vote($this->createToken($landlord), $place, [PlaceVoter::REQUEST_CHANGE]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testRegularUserCannotAccessPlace(): void
    {
        $regularUser = $this->createUser('user@example.com');

        $place = $this->createPlace();

        $result = $this->voter->vote($this->createToken($regularUser), $place, [PlaceVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAnonymousUserCannotAccessPlace(): void
    {
        $place = $this->createPlace();

        $result = $this->voter->vote($this->createToken(null), $place, [PlaceVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAbstainsForUnsupportedAttribute(): void
    {
        $user = $this->createUser();
        $place = $this->createPlace();

        $result = $this->voter->vote($this->createToken($user), $place, ['UNSUPPORTED_ATTRIBUTE']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }

    public function testAbstainsForNonPlaceSubject(): void
    {
        $user = $this->createUser();

        $result = $this->voter->vote($this->createToken($user), new \stdClass(), [PlaceVoter::VIEW]);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}
