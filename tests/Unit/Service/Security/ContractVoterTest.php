<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Security;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Enum\UserRole;
use App\Service\Security\ContractVoter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Uid\Uuid;

class ContractVoterTest extends TestCase
{
    private ContractVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new ContractVoter();
    }

    /**
     * @param array<string> $roles
     */
    private function createUser(array $roles = ['ROLE_USER']): User
    {
        $user = new User(
            Uuid::v7(),
            'test'.uniqid().'@example.com',
            'password',
            'Test',
            'User',
            new \DateTimeImmutable(),
        );

        foreach ($roles as $role) {
            if ('ROLE_USER' !== $role) {
                $user->changeRole(UserRole::from($role), new \DateTimeImmutable());
            }
        }

        return $user;
    }

    private function createContract(User $contractUser, User $placeOwner, RentalType $rentalType = RentalType::LIMITED): Contract
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $placeOwner,
            createdAt: new \DateTimeImmutable(),
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test Type',
            width: 100,
            height: 200,
            length: 150,
            pricePerWeek: 10000,
            pricePerMonth: 35000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            createdAt: new \DateTimeImmutable(),
        );

        $order = new Order(
            id: Uuid::v7(),
            user: $contractUser,
            storage: $storage,
            rentalType: $rentalType,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2024-01-15'),
            endDate: RentalType::UNLIMITED === $rentalType ? null : new \DateTimeImmutable('2024-02-15'),
            totalPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable('2024-01-01'),
        );

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $contractUser,
            storage: $storage,
            rentalType: $rentalType,
            startDate: $order->startDate,
            endDate: $order->endDate,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    public function testUserCanViewOwnContract(): void
    {
        $user = $this->createUser();
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $contract = $this->createContract($user, $landlord);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $contract, [ContractVoter::VIEW]);

        $this->assertSame(1, $result);
    }

    public function testUserCanDownloadOwnContract(): void
    {
        $user = $this->createUser();
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $contract = $this->createContract($user, $landlord);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $contract, [ContractVoter::DOWNLOAD]);

        $this->assertSame(1, $result);
    }

    public function testUserCannotViewOtherUserContract(): void
    {
        $contractOwner = $this->createUser();
        $otherUser = $this->createUser();
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $contract = $this->createContract($contractOwner, $landlord);
        $token = $this->createToken($otherUser);

        $result = $this->voter->vote($token, $contract, [ContractVoter::VIEW]);

        $this->assertSame(-1, $result);
    }

    public function testUserCanTerminateOwnUnlimitedContract(): void
    {
        $user = $this->createUser();
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $contract = $this->createContract($user, $landlord, RentalType::UNLIMITED);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $contract, [ContractVoter::TERMINATE]);

        $this->assertSame(1, $result);
    }

    public function testUserCannotTerminateLimitedContract(): void
    {
        $user = $this->createUser();
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $contract = $this->createContract($user, $landlord, RentalType::LIMITED);
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $contract, [ContractVoter::TERMINATE]);

        $this->assertSame(-1, $result);
    }

    public function testUserCannotTerminateAlreadyTerminatedContract(): void
    {
        $user = $this->createUser();
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $contract = $this->createContract($user, $landlord, RentalType::UNLIMITED);
        $contract->terminate(new \DateTimeImmutable());
        $token = $this->createToken($user);

        $result = $this->voter->vote($token, $contract, [ContractVoter::TERMINATE]);

        $this->assertSame(-1, $result);
    }

    public function testLandlordCanViewContractForOwnStorage(): void
    {
        $tenant = $this->createUser();
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $contract = $this->createContract($tenant, $landlord);
        $token = $this->createToken($landlord);

        $result = $this->voter->vote($token, $contract, [ContractVoter::VIEW]);

        $this->assertSame(1, $result);
    }

    public function testLandlordCannotTerminateUserContract(): void
    {
        $tenant = $this->createUser();
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $contract = $this->createContract($tenant, $landlord, RentalType::UNLIMITED);
        $token = $this->createToken($landlord);

        $result = $this->voter->vote($token, $contract, [ContractVoter::TERMINATE]);

        $this->assertSame(-1, $result);
    }

    public function testAdminCanViewAnyContract(): void
    {
        $tenant = $this->createUser();
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $admin = $this->createUser(['ROLE_ADMIN']);
        $contract = $this->createContract($tenant, $landlord);
        $token = $this->createToken($admin);

        $result = $this->voter->vote($token, $contract, [ContractVoter::VIEW]);

        $this->assertSame(1, $result);
    }

    public function testAdminCanTerminateAnyContract(): void
    {
        $tenant = $this->createUser();
        $landlord = $this->createUser(['ROLE_LANDLORD']);
        $admin = $this->createUser(['ROLE_ADMIN']);
        $contract = $this->createContract($tenant, $landlord, RentalType::UNLIMITED);
        $token = $this->createToken($admin);

        $result = $this->voter->vote($token, $contract, [ContractVoter::TERMINATE]);

        $this->assertSame(1, $result);
    }
}
