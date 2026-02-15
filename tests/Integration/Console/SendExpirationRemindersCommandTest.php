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
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

class SendExpirationRemindersCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private Application $application;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();

        /** @var ClockInterface $clock */
        $clock = $container->get(ClockInterface::class);
        $this->clock = $clock;

        $this->application = new Application(self::$kernel);
    }

    private function createUser(string $email): User
    {
        $user = new User(Uuid::v7(), $email, 'password', 'Test', 'User', $this->clock->now());
        $this->entityManager->persist($user);

        return $user;
    }

    private function createPlace(): Place
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $this->clock->now(),
        );
        $this->entityManager->persist($place);

        return $place;
    }

    private function createStorageType(): StorageType
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $this->createPlace(),
            name: 'Test Type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: $this->clock->now(),
        );
        $this->entityManager->persist($storageType);

        return $storageType;
    }

    private function createStorage(StorageType $storageType, Place $place, string $number): Storage
    {
        $storage = new Storage(
            id: Uuid::v7(),
            number: $number,
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $this->clock->now(),
        );
        $this->entityManager->persist($storage);

        return $storage;
    }

    private function createCompletedOrder(User $user, Storage $storage, \DateTimeImmutable $endDate): Order
    {
        $now = $this->clock->now();
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

        // Set status and paidAt directly via reflection to avoid triggering OrderPaid event
        // (which would cause invoice creation with predictable IDs that conflict with fixtures)
        $reflection = new \ReflectionClass($order);
        $statusProperty = $reflection->getProperty('status');
        $statusProperty->setValue($order, OrderStatus::COMPLETED);
        $paidAtProperty = $reflection->getProperty('paidAt');
        $paidAtProperty->setValue($order, $now);

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
            createdAt: $this->clock->now(),
        );
        $this->entityManager->persist($contract);

        return $contract;
    }

    public function testSendsRemindersForContractsExpiringSoon(): void
    {
        $now = $this->clock->now();
        $tenant = $this->createUser('tenant-reminder@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage1 = $this->createStorage($storageType, $place, 'REM1');
        $storage2 = $this->createStorage($storageType, $place, 'REM2');

        // Contract expiring in 7 days
        $sevenDaysFromNow = $now->modify('+7 days')->setTime(12, 0, 0);
        $order1 = $this->createCompletedOrder($tenant, $storage1, $sevenDaysFromNow);
        $this->createContract($order1);

        // Contract expiring in 1 day
        $oneDayFromNow = $now->modify('+1 day')->setTime(12, 0, 0);
        $order2 = $this->createCompletedOrder($tenant, $storage2, $oneDayFromNow);
        $this->createContract($order2);

        $this->entityManager->flush();

        // Run the command
        $command = $this->application->find('app:send-expiration-reminders');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();
        $output = $commandTester->getDisplay();

        // Should have sent 3 reminders: 2 for 7-day (including fixture contract), 1 for 1-day
        $this->assertStringContainsString('Sent 3 expiration reminder(s)', $output);
    }

    public function testNoRemindersWhenNoContractsExpiringSoon(): void
    {
        $now = $this->clock->now();

        // Remove fixture contracts that would interfere with this test
        $this->entityManager->createQueryBuilder()
            ->delete(Contract::class, 'c')
            ->where('c.endDate IS NOT NULL')
            ->andWhere('c.endDate > :now')
            ->andWhere('c.endDate <= :maxDate')
            ->setParameter('now', $now)
            ->setParameter('maxDate', $now->modify('+7 days'))
            ->getQuery()
            ->execute();

        // Create contract that expires in 30 days (not 7 or 1)
        $tenant = $this->createUser('tenant-noreminder@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'NOREM1');

        $thirtyDaysFromNow = $now->modify('+30 days')->setTime(12, 0, 0);
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
        $now = $this->clock->now();

        // Remove fixture contracts that would interfere with this test
        $this->entityManager->createQueryBuilder()
            ->delete(Contract::class, 'c')
            ->where('c.endDate IS NOT NULL')
            ->andWhere('c.endDate > :now')
            ->andWhere('c.endDate <= :maxDate')
            ->setParameter('now', $now)
            ->setParameter('maxDate', $now->modify('+7 days'))
            ->getQuery()
            ->execute();

        $tenant = $this->createUser('tenant-unlimited@test.com');
        $place = $this->createPlace();
        $storageType = $this->createStorageType();
        $storage = $this->createStorage($storageType, $place, 'UNLIM1');

        // Create order with no end date (unlimited)
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

        // Set status and paidAt directly via reflection to avoid triggering OrderPaid event
        $reflection = new \ReflectionClass($order);
        $statusProperty = $reflection->getProperty('status');
        $statusProperty->setValue($order, OrderStatus::COMPLETED);
        $paidAtProperty = $reflection->getProperty('paidAt');
        $paidAtProperty->setValue($order, $now);

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
            createdAt: $now,
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
