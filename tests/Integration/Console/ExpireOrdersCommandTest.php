<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\DataFixtures\UserFixtures;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
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

        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $this->clock->now();
        $pastDate = $now->modify('-10 days');
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');

        // Create orders that are already expired (created 10 days ago)
        $order1 = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $startDate,
            $endDate,
            $pastDate,
        );

        $order2 = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $startDate,
            $endDate,
            $pastDate,
        );

        $order1->reserve($pastDate);
        $order2->reserve($pastDate);
        $this->entityManager->flush();

        // Both orders should be reserved but not yet marked as expired
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

    public function testExpirySkipsOrderWithInFlightPaymentSession(): void
    {
        // The customer may be typing card details on the GoPay gateway right
        // now — expiring the order under them would release the storage and
        // strand their imminent PAID webhook. Pending session → skip this pass.
        $order = $this->createOverdueOrderWithPayment();
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);
        $this->goPayClient()->simulatePaymentPending($paymentId);

        $this->runExpireCommand();

        $this->entityManager->refresh($order);
        $this->assertSame(OrderStatus::AWAITING_PAYMENT, $order->status, 'Order with in-flight payment session must not be expired.');
    }

    public function testExpiryReconcilesPaidSessionInsteadOfExpiring(): void
    {
        // GoPay says PAID but the webhook never reached us — the cron must
        // reconcile (confirm + complete), not expire a paid order.
        $order = $this->createOverdueOrderWithPayment();
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);
        $this->goPayClient()->simulatePaymentPaid($paymentId);

        $this->runExpireCommand();

        $this->entityManager->refresh($order);
        $this->assertSame(OrderStatus::COMPLETED, $order->status, 'Paid-but-unreported order must be completed, not expired.');
    }

    public function testExpiryProceedsWhenPaymentSessionIsDead(): void
    {
        $order = $this->createOverdueOrderWithPayment();
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);
        $this->goPayClient()->simulatePaymentTimeouted($paymentId);

        $this->runExpireCommand();

        $this->entityManager->refresh($order);
        $this->assertSame(OrderStatus::EXPIRED, $order->status, 'Order with a dead payment session past expiresAt must expire.');
        $this->assertNull($order->goPayPaymentId, 'The dead session ID must have been cleared during reconciliation.');
    }

    private function createOverdueOrderWithPayment(): Order
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $this->clock->now();
        $pastDate = $now->modify('-10 days');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $now->modify('+1 day'),
            $now->modify('+30 days'),
            $pastDate, // created in the past → expiresAt already passed
        );
        $order->acceptTerms($now);

        $commandBus = static::getContainer()->get('test.command.bus');
        $commandBus->dispatch(new \App\Command\InitiatePaymentCommand(
            order: $order,
            returnUrl: 'https://example.com/return',
            notificationUrl: 'https://example.com/webhook',
        ));
        $this->entityManager->flush();

        return $order;
    }

    private function goPayClient(): \App\Tests\Mock\MockGoPayClient
    {
        /** @var \App\Tests\Mock\MockGoPayClient $client */
        $client = static::getContainer()->get(\App\Tests\Mock\MockGoPayClient::class);

        return $client;
    }

    private function runExpireCommand(): void
    {
        $command = $this->application->find('app:expire-orders');
        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
        $commandTester->assertCommandIsSuccessful();
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
