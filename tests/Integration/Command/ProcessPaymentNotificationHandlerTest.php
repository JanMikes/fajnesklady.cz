<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\InitiatePaymentCommand;
use App\Command\ProcessPaymentNotificationCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\RentalType;
use App\Service\OrderService;
use App\Tests\Mock\MockGoPayClient;
use App\Value\GoPayPayment;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class ProcessPaymentNotificationHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private OrderService $orderService;
    private ClockInterface $clock;
    private MockGoPayClient $goPayClient;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->commandBus = $container->get('command.bus');
        $this->orderService = $container->get(OrderService::class);
        $this->clock = $container->get(ClockInterface::class);

        /** @var MockGoPayClient $goPayClient */
        $goPayClient = $container->get(MockGoPayClient::class);
        $this->goPayClient = $goPayClient;
        $this->goPayClient->reset();
    }

    public function testProcessPaymentNotificationConfirmsPaymentOnPaid(): void
    {
        $order = $this->createAndInitiatePayment(RentalType::LIMITED);
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);

        // Simulate payment completion in GoPay
        $this->goPayClient->simulatePaymentPaid($paymentId);

        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));

        $this->entityManager->clear();
        $refreshedOrder = $this->entityManager->find(\App\Entity\Order::class, $order->id);

        $this->assertSame(OrderStatus::PAID, $refreshedOrder->status);
        $this->assertNotNull($refreshedOrder->paidAt);
    }

    public function testProcessPaymentNotificationCancelsOrderOnCanceled(): void
    {
        $order = $this->createAndInitiatePayment(RentalType::LIMITED);
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);

        // Simulate payment cancellation in GoPay
        $this->goPayClient->simulatePaymentCanceled($paymentId);

        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));

        $this->entityManager->clear();
        $refreshedOrder = $this->entityManager->find(\App\Entity\Order::class, $order->id);

        $this->assertSame(OrderStatus::CANCELLED, $refreshedOrder->status);
    }

    public function testProcessPaymentNotificationStoresParentPaymentIdForRecurring(): void
    {
        $order = $this->createAndInitiatePayment(RentalType::UNLIMITED);
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);

        // Simulate payment completion in GoPay
        $this->goPayClient->simulatePaymentPaid($paymentId);

        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));

        $this->entityManager->clear();
        $refreshedOrder = $this->entityManager->find(\App\Entity\Order::class, $order->id);

        $this->assertSame(OrderStatus::PAID, $refreshedOrder->status);
        // Parent payment ID should be set (in mock it equals the original payment ID as parentId)
        $this->assertNotNull($refreshedOrder->goPayParentPaymentId);
    }

    private function createAndInitiatePayment(RentalType $rentalType): \App\Entity\Order
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = RentalType::LIMITED === $rentalType ? $now->modify('+30 days') : null;

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $rentalType,
            $startDate,
            $endDate,
        );

        $envelope = $this->commandBus->dispatch(new InitiatePaymentCommand(
            order: $order,
            returnUrl: 'https://example.com/return',
            notificationUrl: 'https://example.com/webhook',
        ));

        /** @var HandledStamp $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);

        return $order;
    }
}
