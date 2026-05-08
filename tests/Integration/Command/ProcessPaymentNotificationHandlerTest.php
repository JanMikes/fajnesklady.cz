<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\InitiatePaymentCommand;
use App\Command\ProcessPaymentNotificationCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\Payment;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\RentalType;
use App\Service\Identity\ProvideIdentity;
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

    public function testProcessPaymentNotificationIsIdempotentForDuplicateRecurringWebhooks(): void
    {
        // Arrange: an active recurring contract.
        $order = $this->createAndInitiatePayment(RentalType::UNLIMITED);
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);

        $this->goPayClient->simulatePaymentPaid($paymentId);
        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(\App\Entity\Contract::class, 'c')
            ->where('c.order = :order')
            ->setParameter('order', $order->id)
            ->getQuery()
            ->getSingleResult();

        $parentPaymentId = $contract->goPayParentPaymentId;
        \assert(null !== $parentPaymentId);
        $recurringPaymentId = 'gp_recurring_dup_42';

        $this->goPayClient->simulatePaymentPaid($recurringPaymentId);
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

        // Act: dispatch the same recurring webhook twice.
        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($recurringPaymentId));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $afterFirst = $this->entityManager->find(\App\Entity\Contract::class, $contract->id);
        \assert($afterFirst instanceof \App\Entity\Contract);
        $nextBillingAfterFirst = $afterFirst->nextBillingDate;
        $paidThroughAfterFirst = $afterFirst->paidThroughDate;

        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($recurringPaymentId));
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Assert: only one Payment row exists for this GoPay payment ID and
        // the contract's billing dates did NOT advance a second time.
        $paymentCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Payment::class, 'p')
            ->where('p.goPayPaymentId = :paymentId')
            ->setParameter('paymentId', $recurringPaymentId)
            ->getQuery()
            ->getSingleScalarResult();

        $this->assertSame(1, $paymentCount, 'Duplicate webhook must not create a second Payment row.');

        $afterSecond = $this->entityManager->find(\App\Entity\Contract::class, $contract->id);
        \assert($afterSecond instanceof \App\Entity\Contract);
        $this->assertEquals(
            $nextBillingAfterFirst?->format(\DateTimeInterface::ATOM),
            $afterSecond->nextBillingDate?->format(\DateTimeInterface::ATOM),
            'Duplicate webhook must not advance nextBillingDate twice.',
        );
        $this->assertEquals(
            $paidThroughAfterFirst?->format(\DateTimeInterface::ATOM),
            $afterSecond->paidThroughDate?->format(\DateTimeInterface::ATOM),
            'Duplicate webhook must not advance paidThroughDate twice.',
        );
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

    public function testParallelWebhookRaceLosesGracefullyWithoutDuplicatePayment(): void
    {
        // Simulates two simultaneous webhooks for the same recurring payment
        // ID by pre-inserting a Payment row (the "winner" of the race) and
        // then dispatching the notification command (the "loser"). The handler
        // must not produce a second Payment row and must not throw — duplicate
        // is the expected outcome.
        $order = $this->createAndInitiatePayment(RentalType::UNLIMITED);
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);

        $this->goPayClient->simulatePaymentPaid($paymentId);
        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(\App\Entity\Contract::class, 'c')
            ->where('c.order = :order')
            ->setParameter('order', $order->id)
            ->getQuery()
            ->getSingleResult();

        $parentPaymentId = $contract->goPayParentPaymentId;
        \assert(null !== $parentPaymentId);
        $recurringPaymentId = 'gp_recurring_race_777';

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

        // Approximate the race: pre-insert a Payment row with the same
        // goPayPaymentId. When the handler runs, the early
        // existsByGoPayPaymentId() check at the top of __invoke fires and
        // short-circuits — that's the first line of defense.
        /** @var ProvideIdentity $identityProvider */
        $identityProvider = static::getContainer()->get(ProvideIdentity::class);
        $existingPayment = new Payment(
            id: $identityProvider->next(),
            order: null,
            contract: $contract,
            storage: $contract->storage,
            amount: $contract->storage->getEffectivePricePerMonth(),
            paidAt: $this->clock->now(),
            createdAt: $this->clock->now(),
        );
        $existingPayment->setGoPayPaymentId($recurringPaymentId);
        $this->entityManager->persist($existingPayment);
        $this->entityManager->flush();
        $this->entityManager->clear();

        // Dispatch — must not throw, duplicate is the expected outcome.
        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($recurringPaymentId));
        $this->entityManager->clear();

        // Still exactly ONE Payment row for this GoPay payment ID.
        $count = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Payment::class, 'p')
            ->where('p.goPayPaymentId = :gp')
            ->setParameter('gp', $recurringPaymentId)
            ->getQuery()
            ->getSingleScalarResult();

        $this->assertSame(1, $count, 'Race-fix: duplicate webhook must not produce a second Payment row.');
    }

    public function testParallelWebhookRaceUniqueIndexBackstop(): void
    {
        // Lower-level pin on the partial unique index: the database itself
        // physically prevents two Payment rows from sharing a non-null
        // go_pay_payment_id. This is the hard backstop for the case where
        // the application-level existsByGoPayPaymentId() check is bypassed
        // (truly simultaneous transactions, both reading the table before
        // either commits).
        $order = $this->createAndInitiatePayment(RentalType::UNLIMITED);
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);

        $this->goPayClient->simulatePaymentPaid($paymentId);
        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(\App\Entity\Contract::class, 'c')
            ->where('c.order = :order')
            ->setParameter('order', $order->id)
            ->getQuery()
            ->getSingleResult();

        /** @var ProvideIdentity $identityProvider */
        $identityProvider = static::getContainer()->get(ProvideIdentity::class);

        $first = new Payment(
            id: $identityProvider->next(),
            order: null,
            contract: $contract,
            storage: $contract->storage,
            amount: 100_000,
            paidAt: $this->clock->now(),
            createdAt: $this->clock->now(),
        );
        $first->setGoPayPaymentId('gp_dup_id');
        $this->entityManager->persist($first);
        $this->entityManager->flush();

        $second = new Payment(
            id: $identityProvider->next(),
            order: null,
            contract: $contract,
            storage: $contract->storage,
            amount: 100_000,
            paidAt: $this->clock->now(),
            createdAt: $this->clock->now(),
        );
        $second->setGoPayPaymentId('gp_dup_id');
        $this->entityManager->persist($second);

        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->entityManager->flush();
    }

    public function testRecurringWebhookAmountMismatchLogsWarningAndDispatchesAlertEvent(): void
    {
        // Webhook reports an amount different from the locked-in monthly. The
        // handler must (a) record what GoPay says (GoPay is the source of
        // truth for what was actually charged) and (b) dispatch a
        // PaymentAmountMismatch event so admin is alerted.
        $order = $this->createAndInitiatePayment(RentalType::UNLIMITED);
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);

        $this->goPayClient->simulatePaymentPaid($paymentId);
        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(\App\Entity\Contract::class, 'c')
            ->where('c.order = :order')
            ->setParameter('order', $order->id)
            ->getQuery()
            ->getSingleResult();

        $parentPaymentId = $contract->goPayParentPaymentId;
        \assert(null !== $parentPaymentId);
        $recurringPaymentId = 'gp_recurring_mismatch_42';

        // Expected = storage monthly. Wrong amount = expected - 100 Kč
        $expected = $contract->storage->getEffectivePricePerMonth();
        $received = $expected - 10_000;

        $reflection = new \ReflectionClass($this->goPayClient);
        $prop = $reflection->getProperty('paymentStatuses');
        $statuses = $prop->getValue($this->goPayClient);
        $statuses[$recurringPaymentId] = new \App\Value\GoPayPaymentStatus(
            id: $recurringPaymentId,
            state: 'PAID',
            parentId: $parentPaymentId,
            amount: $received,
        );
        $prop->setValue($this->goPayClient, $statuses);

        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($recurringPaymentId));
        $this->entityManager->clear();

        // Assert: Payment row recorded with the ACTUAL amount GoPay sent (not
        // the expected). GoPay is the source of truth for what was charged.
        $payment = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->where('p.goPayPaymentId = :gp')
            ->setParameter('gp', $recurringPaymentId)
            ->getQuery()
            ->getOneOrNullResult();

        $this->assertNotNull($payment);
        $this->assertSame($received, $payment->amount, 'Payment row must record what GoPay actually charged.');
    }

    public function testOrderWebhookAmountMismatchDispatchesAlertEvent(): void
    {
        // Order branch (initial GoPay charge): the webhook reports an amount
        // different from the order's firstPaymentPrice. The handler must
        // dispatch a PaymentAmountMismatch event for admin visibility.
        $order = $this->createAndInitiatePayment(RentalType::LIMITED);
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);

        // Override the GoPay status to report a different amount than the order expects.
        $reflection = new \ReflectionClass($this->goPayClient);
        $prop = $reflection->getProperty('paymentStatuses');
        $statuses = $prop->getValue($this->goPayClient);
        $statuses[$paymentId] = new \App\Value\GoPayPaymentStatus(
            id: $paymentId,
            state: 'PAID',
            parentId: $paymentId,
            amount: $order->firstPaymentPrice - 5_000,
        );
        $prop->setValue($this->goPayClient, $statuses);

        // Dispatch must not throw — mismatch is logged + event dispatched, but
        // the order is still confirmed because GoPay says it was paid.
        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));

        $this->entityManager->clear();
        $refreshedOrder = $this->entityManager->find(\App\Entity\Order::class, $order->id);
        $this->assertNotNull($refreshedOrder->paidAt, 'Order should still be confirmed even when amount mismatches.');
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
