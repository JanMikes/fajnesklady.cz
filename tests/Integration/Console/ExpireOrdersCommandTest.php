<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\DataFixtures\UserFixtures;
use App\Entity\Order;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\RentalType;
use App\Enum\StorageStatus;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ExpireOrdersCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private OrderService $orderService;
    private ClockInterface $clock;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->orderService = $container->get(OrderService::class);
        $this->clock = $container->get(ClockInterface::class);

        $this->application = new Application(self::$kernel);
    }

    public function testExpireOrdersCommandExpiresOverdueOrders(): void
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);

        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);

        $now = $this->clock->now();
        $pastDate = $now->modify('-10 days');
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');

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

        // Verify command is successful
        $commandTester->assertCommandIsSuccessful();

        // Verify the command output indicates orders were expired (at least our 2)
        $this->assertMatchesRegularExpression('/Expired \d+ order\(s\)/', $commandTester->getDisplay());

        // Verify our test orders are now expired
        $this->entityManager->refresh($order1);
        $this->entityManager->refresh($order2);
        $this->assertSame(OrderStatus::EXPIRED, $order1->status);
        $this->assertSame(OrderStatus::EXPIRED, $order2->status);

        // Verify storages are released
        $this->assertSame(StorageStatus::AVAILABLE, $order1->storage->status);
        $this->assertSame(StorageStatus::AVAILABLE, $order2->storage->status);
    }

    public function testExpireOrdersCommandOutputsCount(): void
    {
        $now = $this->clock->now();

        // Count expirable orders from fixtures
        $expirableOrders = $this->countExpirableOrders($now);

        $command = $this->application->find('app:expire-orders');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $commandTester->assertCommandIsSuccessful();

        if ($expirableOrders > 0) {
            $this->assertStringContainsString("Expired {$expirableOrders} order(s)", $commandTester->getDisplay());
        } else {
            $this->assertStringContainsString('No orders to expire', $commandTester->getDisplay());
        }
    }

    private function countExpirableOrders(\DateTimeImmutable $now): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(o.id)')
            ->from(Order::class, 'o')
            ->where('o.expiresAt < :now')
            ->andWhere('o.status NOT IN (:terminalStatuses)')
            ->andWhere('o.status != :paidStatus')
            ->setParameter('now', $now)
            ->setParameter('terminalStatuses', [OrderStatus::COMPLETED, OrderStatus::CANCELLED, OrderStatus::EXPIRED])
            ->setParameter('paidStatus', OrderStatus::PAID)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
