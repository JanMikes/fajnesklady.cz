<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\RentalType;
use App\Enum\StorageStatus;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

class ExpireOrdersCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private OrderService $orderService;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->orderService = $container->get(OrderService::class);

        $this->application = new Application(self::$kernel);
    }

    private function createUser(string $email): User
    {
        $user = new User(Uuid::v7(), $email, 'password', 'Test', 'User', new \DateTimeImmutable());
        $this->entityManager->persist($user);

        return $user;
    }

    private function createPlace(User $owner): Place
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
        $this->entityManager->persist($place);

        return $place;
    }

    private function createStorageType(Place $place): StorageType
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Test Type',
            width: 100,
            height: 100,
            length: 100,
            pricePerWeek: 10000,
            pricePerMonth: 35000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($storageType);

        return $storageType;
    }

    private function createStorage(StorageType $storageType, string $number): Storage
    {
        $storage = new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($storage);

        return $storage;
    }

    public function testExpireOrdersCommandExpiresOverdueOrders(): void
    {
        $owner = $this->createUser('owner-cmd@test.com');
        $tenant = $this->createUser('tenant-cmd@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $this->createStorage($storageType, 'CMD1');
        $this->createStorage($storageType, 'CMD2');
        $this->entityManager->flush();

        $pastDate = new \DateTimeImmutable('-10 days');
        $startDate = new \DateTimeImmutable('+1 day');
        $endDate = new \DateTimeImmutable('+30 days');

        // Create orders that are already expired (created 10 days ago)
        $order1 = $this->orderService->createOrder(
            $tenant,
            $storageType,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            null,
            $pastDate,
        );

        $order2 = $this->orderService->createOrder(
            $tenant,
            $storageType,
            RentalType::LIMITED,
            $startDate,
            $endDate,
            null,
            $pastDate,
        );

        $this->entityManager->flush();

        // Both orders should be expired but not yet marked as expired
        $this->assertSame(OrderStatus::RESERVED, $order1->status);
        $this->assertSame(OrderStatus::RESERVED, $order2->status);

        // Run the command
        $command = $this->application->find('app:expire-orders');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        // Verify command output
        $commandTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Expired 2 order(s)', $commandTester->getDisplay());

        // Verify orders are now expired
        $this->assertSame(OrderStatus::EXPIRED, $order1->status);
        $this->assertSame(OrderStatus::EXPIRED, $order2->status);

        // Verify storages are released
        $this->assertSame(StorageStatus::AVAILABLE, $order1->storage->status);
        $this->assertSame(StorageStatus::AVAILABLE, $order2->storage->status);
    }

    public function testExpireOrdersCommandWithNoOrdersToExpire(): void
    {
        $command = $this->application->find('app:expire-orders');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('No orders to expire', $commandTester->getDisplay());
    }
}
