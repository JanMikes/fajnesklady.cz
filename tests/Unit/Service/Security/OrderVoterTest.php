<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Security;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Service\Security\OrderVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

class OrderVoterTest extends TestCase
{
    private OrderVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new OrderVoter();
    }

    /**
     * @param array<string> $roles
     */
    private function createUser(array $roles = ['ROLE_USER']): User
    {
        $user = new User(
            Uuid::v7(),
            'test@example.com',
            'password',
            'Test',
            'User',
            new \DateTimeImmutable(),
        );

        foreach ($roles as $role) {
            if ('ROLE_USER' !== $role) {
                $user->changeRole(\App\Enum\UserRole::from($role), new \DateTimeImmutable());
            }
        }

        return $user;
    }

    private function createOrder(User $orderUser, ?User $storageOwner = null): Order
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
            name: 'Test Type',
            innerWidth: 100,
            innerHeight: 200,
            innerLength: 150,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: new \DateTimeImmutable(),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
            owner: $storageOwner,
        );

        return new Order(
            id: Uuid::v7(),
            user: $orderUser,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2024-01-15'),
            endDate: new \DateTimeImmutable('2024-02-15'),
            totalPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable('2024-01-01'),
        );
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    public function testUserCanViewOwnOrder(): void
    {
        $user = $this->createUser();
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $order = $this->createOrder($user, $landlord);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $order, [OrderVoter::VIEW]);

        $this->assertSame(1, $result);
    }

    public function testUserCannotViewOtherUserOrder(): void
    {
        $orderOwner = $this->createUser();
        $otherUser = new User(
            Uuid::v7(),
            'other@example.com',
            'password',
            'Other',
            'User',
            new \DateTimeImmutable(),
        );
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $order = $this->createOrder($orderOwner, $landlord);
        $token = $this->createToken($otherUser);

        $result = $this->voter->vote($token, $order, [OrderVoter::VIEW]);

        $this->assertSame(-1, $result);
    }

    public function testLandlordCanViewOrderForOwnStorage(): void
    {
        $tenant = $this->createUser();
        $landlord = new User(
            Uuid::v7(),
            'landlord@example.com',
            'password',
            'Landlord',
            'User',
            new \DateTimeImmutable(),
        );
        $landlord->changeRole(\App\Enum\UserRole::LANDLORD, new \DateTimeImmutable());

        $order = $this->createOrder($tenant, $landlord);
        $token = $this->createToken($landlord);

        $result = $this->voter->vote($token, $order, [OrderVoter::VIEW]);

        $this->assertSame(1, $result);
    }

    public function testLandlordCannotViewOrderForOtherLandlordStorage(): void
    {
        $tenant = $this->createUser();
        $otherLandlord = new User(
            Uuid::v7(),
            'otherlandlord@example.com',
            'password',
            'Other',
            'Landlord',
            new \DateTimeImmutable(),
        );
        $otherLandlord->changeRole(\App\Enum\UserRole::LANDLORD, new \DateTimeImmutable());

        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $order = $this->createOrder($tenant, $landlord);
        $token = $this->createToken($otherLandlord);

        $result = $this->voter->vote($token, $order, [OrderVoter::VIEW]);

        $this->assertSame(-1, $result);
    }

    public function testAdminCanViewAnyOrder(): void
    {
        $tenant = $this->createUser();
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $admin = new User(
            Uuid::v7(),
            'admin@example.com',
            'password',
            'Admin',
            'User',
            new \DateTimeImmutable(),
        );
        $admin->changeRole(\App\Enum\UserRole::ADMIN, new \DateTimeImmutable());

        $order = $this->createOrder($tenant, $landlord);
        $token = $this->createToken($admin);

        $result = $this->voter->vote($token, $order, [OrderVoter::VIEW]);

        $this->assertSame(1, $result);
    }

    public function testUserCanCancelOwnOrder(): void
    {
        $user = $this->createUser();
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $order = $this->createOrder($user, $landlord);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $order, [OrderVoter::CANCEL]);

        $this->assertSame(1, $result);
    }
}
