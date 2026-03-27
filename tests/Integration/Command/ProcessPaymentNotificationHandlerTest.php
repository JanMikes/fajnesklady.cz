<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\InitiatePaymentCommand;
use App\Command\ProcessPaymentNotificationCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\RentalType;
use App\Service\OrderService;
use App\Tests\Mock\MockGoPayClient;
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
        $this->commandBus = $container->get('test.command.bus');
        $this->orderService = $container->get(OrderService::class);
        $this->clock = $container->get(ClockInterface::class);

        /** @var MockGoPayClient $goPayClient */
        $goPayClient = $container->get(MockGoPayClient::class);
        $this->goPayClient = $goPayClient;
        $this->goPayClient->reset();
    }

    public function testProcessPaymentNotificationConfirmsAndCompletesOrder(): void
    {
        $order = $this->createAndInitiatePayment(RentalType::LIMITED);
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);

        // Simulate payment completion in GoPay
        $this->goPayClient->simulatePaymentPaid($paymentId);

        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));

        $this->entityManager->clear();
        $refreshedOrder = $this->entityManager->find(\App\Entity\Order::class, $order->id);

        // Order should be auto-completed since terms were accepted before payment
        $this->assertSame(OrderStatus::COMPLETED, $refreshedOrder->status);
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

        // Order should be auto-completed since terms were accepted before payment
        $this->assertSame(OrderStatus::COMPLETED, $refreshedOrder->status);
        // Parent payment ID should be set (in mock it equals the original payment ID as parentId)
        $this->assertNotNull($refreshedOrder->goPayParentPaymentId);
    }

    public function testProcessPaymentNotificationReconcileRecurringPayment(): void
    {
        // Set up a contract with recurring payment (same as ChargeRecurringPaymentHandlerTest)
        $order = $this->createAndInitiatePayment(RentalType::UNLIMITED);
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);

        // Complete the initial payment flow
        $this->goPayClient->simulatePaymentPaid($paymentId);
        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Find the contract created by auto-completion
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(\App\Entity\Contract::class, 'c')
            ->where('c.order = :order')
            ->setParameter('order', $order->id)
            ->getQuery()
            ->getSingleResult();

        $this->assertNotNull($contract->goPayParentPaymentId, 'Contract should have recurring payment set up');
        $originalNextBillingDate = $contract->nextBillingDate;

        // Simulate: a recurring charge was created by GoPay but handler didn't see PAID.
        // GoPay later sends a webhook notification with the new payment ID.
        $parentPaymentId = $contract->goPayParentPaymentId;
        $recurringPaymentId = 'gp_recurring_99999';

        // Register the recurring payment status in mock (as GoPay would return it)
        $this->goPayClient->simulatePaymentPaid($recurringPaymentId);
        // Override the status to include parent_id and amount
        $reflection = new \ReflectionClass($this->goPayClient);
        $prop = $reflection->getProperty('paymentStatuses');
        $statuses = $prop->getValue($this->goPayClient);
        $statuses[$recurringPaymentId] = new \App\Value\GoPayPaymentStatus(
            id: $recurringPaymentId,
            state: 'PAID',
            parentId: $parentPaymentId,
            amount: $contract->storage->getEffectivePricePerMonth(),
        );
        $prop->setValue($this->goPayClient, $statuses);

        // Process the webhook notification
        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($recurringPaymentId));

        $this->entityManager->clear();
        $refreshedContract = $this->entityManager->find(\App\Entity\Contract::class, $contract->id);

        // Verify billing was reconciled
        $this->assertNotNull($refreshedContract->lastBilledAt);
        $this->assertSame(0, $refreshedContract->failedBillingAttempts);

        // Next billing date should have advanced
        if (null !== $originalNextBillingDate) {
            $this->assertNotEquals(
                $originalNextBillingDate->format('Y-m-d'),
                $refreshedContract->nextBillingDate?->format('Y-m-d'),
            );
        }
    }

    private function createAndInitiatePayment(RentalType $rentalType): \App\Entity\Order
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = RentalType::LIMITED === $rentalType ? $now->modify('+30 days') : null;

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $rentalType,
            $startDate,
            $endDate,
            $now,
        );

        // Accept terms before payment (new flow requirement)
        $order->acceptTerms($now);

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
