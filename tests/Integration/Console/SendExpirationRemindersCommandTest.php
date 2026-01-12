<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\RentalType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

class SendExpirationRemindersCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();

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

    private function createCompletedOrder(User $user, Storage $storage, \DateTimeImmutable $endDate): Order
    {
        $now = new \DateTimeImmutable();
        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: $now->modify('-30 days'),
            endDate: $endDate,
            totalPrice: 35000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now->modify('-30 days'),
        );
        $order->reserve($now);
        $order->markPaid($now);

        // Set status to COMPLETED directly via reflection (for test setup)
        $reflection = new \ReflectionClass($order);
        $property = $reflection->getProperty('status');
        $property->setValue($order, OrderStatus::COMPLETED);

        $this->entityManager->persist($order);

        return $order;
    }

    private function createContract(Order $order): Contract
    {
        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            rentalType: $order->rentalType,
            startDate: $order->startDate,
            endDate: $order->endDate,
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($contract);

        return $contract;
    }

    public function testSendsRemindersForContractsExpiringSoon(): void
    {
        $owner = $this->createUser('owner-reminder@test.com');
        $tenant = $this->createUser('tenant-reminder@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage1 = $this->createStorage($storageType, 'REM1');
        $storage2 = $this->createStorage($storageType, 'REM2');

        // Contract expiring in 7 days
        $sevenDaysFromNow = (new \DateTimeImmutable())->modify('+7 days')->setTime(12, 0, 0);
        $order1 = $this->createCompletedOrder($tenant, $storage1, $sevenDaysFromNow);
        $this->createContract($order1);

        // Contract expiring in 1 day
        $oneDayFromNow = (new \DateTimeImmutable())->modify('+1 day')->setTime(12, 0, 0);
        $order2 = $this->createCompletedOrder($tenant, $storage2, $oneDayFromNow);
        $this->createContract($order2);

        $this->entityManager->flush();

        // Run the command
        $command = $this->application->find('app:send-expiration-reminders');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();
        $output = $commandTester->getDisplay();

        // Should have sent 2 reminders (one for 7-day, one for 1-day)
        $this->assertStringContainsString('Sent 2 expiration reminder(s)', $output);
    }

    public function testNoRemindersWhenNoContractsExpiringSoon(): void
    {
        // Create contract that expires in 30 days (not 7 or 1)
        $owner = $this->createUser('owner-noreminder@test.com');
        $tenant = $this->createUser('tenant-noreminder@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, 'NOREM1');

        $thirtyDaysFromNow = (new \DateTimeImmutable())->modify('+30 days')->setTime(12, 0, 0);
        $order = $this->createCompletedOrder($tenant, $storage, $thirtyDaysFromNow);
        $this->createContract($order);

        $this->entityManager->flush();

        // Run the command
        $command = $this->application->find('app:send-expiration-reminders');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();
        $this->assertStringContainsString('No contracts expiring soon', $commandTester->getDisplay());
    }

    public function testSkipsUnlimitedContracts(): void
    {
        $owner = $this->createUser('owner-unlimited@test.com');
        $tenant = $this->createUser('tenant-unlimited@test.com');
        $place = $this->createPlace($owner);
        $storageType = $this->createStorageType($place);
        $storage = $this->createStorage($storageType, 'UNLIM1');

        // Create order with no end date (unlimited)
        $now = new \DateTimeImmutable();
        $order = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storage,
            rentalType: RentalType::UNLIMITED,
            paymentFrequency: null,
            startDate: $now->modify('-30 days'),
            endDate: null, // Unlimited
            totalPrice: 35000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now->modify('-30 days'),
        );
        $order->reserve($now);
        $order->markPaid($now);

        $reflection = new \ReflectionClass($order);
        $property = $reflection->getProperty('status');
        $property->setValue($order, OrderStatus::COMPLETED);

        $this->entityManager->persist($order);

        // Create unlimited contract
        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            rentalType: $order->rentalType,
            startDate: $order->startDate,
            endDate: null, // Unlimited
            createdAt: new \DateTimeImmutable(),
        );
        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        // Run the command
        $command = $this->application->find('app:send-expiration-reminders');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();
        // Unlimited contracts should not generate reminders
        $this->assertStringContainsString('No contracts expiring soon', $commandTester->getDisplay());
    }
}
