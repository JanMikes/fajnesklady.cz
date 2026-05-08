<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ChargeRecurringPaymentCommand;
use App\Command\InitiatePaymentCommand;
use App\Command\ProcessPaymentNotificationCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Service\OrderService;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

class ChargeRecurringPaymentHandlerTest extends KernelTestCase
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

    public function testChargeRecurringPaymentUpdatesContract(): void
    {
        $contract = $this->createContractWithRecurringPayment();
        $now = $this->clock->now();

        $this->assertNull($contract->lastBilledAt, 'Contract should not be billed yet');

        $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));

        $this->entityManager->clear();
        $refreshedContract = $this->entityManager->find(Contract::class, $contract->id);

        // Verify billing was recorded
        $this->assertNotNull($refreshedContract->lastBilledAt);
        $this->assertEquals($now->format('Y-m-d H:i:s'), $refreshedContract->lastBilledAt->format('Y-m-d H:i:s'));
        // Next billing date should advance by 1 month from the previous nextBillingDate
        $expectedNextBilling = $now->modify('+2 months'); // setup sets nextBillingDate to now+1m, charge advances to now+2m
        $this->assertEquals($expectedNextBilling->format('Y-m-d H:i:s'), $refreshedContract->nextBillingDate->format('Y-m-d H:i:s'));
        $this->assertSame(0, $refreshedContract->failedBillingAttempts);
    }

    public function testChargeRecurringPaymentSucceedsAfterPolling(): void
    {
        $contract = $this->createContractWithRecurringPayment();
        $now = $this->clock->now();

        // GoPay returns CREATED initially, but getStatus returns PAID (async confirmation)
        $this->goPayClient->willReturnCreatedForRecurrence();

        $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));

        $this->entityManager->clear();
        $refreshedContract = $this->entityManager->find(Contract::class, $contract->id);

        // Verify billing was recorded despite initial CREATED state
        $this->assertNotNull($refreshedContract->lastBilledAt);
        $this->assertEquals($now->format('Y-m-d H:i:s'), $refreshedContract->lastBilledAt->format('Y-m-d H:i:s'));
        $this->assertSame(0, $refreshedContract->failedBillingAttempts);
    }

    public function testChargeRecurringPaymentThrowsOnError(): void
    {
        $contract = $this->createContractWithRecurringPayment();

        // Simulate GoPay failure
        $this->goPayClient->willFailNextRecurrence();

        $exceptionThrown = false;

        try {
            $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));
        } catch (\Symfony\Component\Messenger\Exception\HandlerFailedException $e) {
            $exceptionThrown = true;
            // The GoPayException is wrapped in HandlerFailedException
            $previous = $e->getPrevious();
            $this->assertInstanceOf(\App\Service\GoPay\GoPayException::class, $previous);
            $this->assertStringContainsString('Simulated recurrence failure', $previous->getMessage());
        }

        $this->assertTrue($exceptionThrown, 'Expected HandlerFailedException to be thrown');
    }

    public function testChargeRespectsContractIndividualMonthlyAmount(): void
    {
        $contract = $this->createContractWithRecurringPayment();

        // Override the recurring monthly to 500 Kč; the underlying storage's
        // default (used to be 1500 Kč) must not leak through.
        $contract->applyIndividualMonthlyAmount(50_000);
        $this->entityManager->flush();

        $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));

        $amounts = $this->goPayClient->getRecurrenceAmounts();
        $this->assertCount(1, $amounts);
        $this->assertSame(50_000, array_values($amounts)[0]);
    }

    public function testChargeSkipsFreeContractsWithoutGoPayCall(): void
    {
        $contract = $this->createContractWithRecurringPayment();
        $contract->applyIndividualMonthlyAmount(0);
        $this->entityManager->flush();

        $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));

        $this->assertSame([], $this->goPayClient->getRecurrenceAmounts());

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Contract::class, $contract->id);
        // No charge → lastBilledAt unchanged
        $this->assertNull($refreshed->lastBilledAt);
    }

    public function testChargeFallsBackToStorageRateWhenNoOverride(): void
    {
        $contract = $this->createContractWithRecurringPayment();
        $storageMonthly = $contract->storage->getEffectivePricePerMonth();

        $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));

        $amounts = $this->goPayClient->getRecurrenceAmounts();
        $this->assertCount(1, $amounts);
        $this->assertSame($storageMonthly, array_values($amounts)[0]);
    }

    public function testShortCircuitsWhenContractWasJustBilled(): void
    {
        // Defensive guard against cron + manual-admin-charge race: if the
        // contract was successfully billed within the last 5 minutes, the
        // handler must skip GoPay entirely.
        $contract = $this->createContractWithRecurringPayment();
        $now = $this->clock->now();

        // Simulate a charge that just landed 2 minutes ago (e.g. by a manual
        // admin action in another process) — recordBillingCharge sets
        // lastBilledAt and bumps nextBillingDate forward.
        $contract->recordBillingCharge(
            $now->modify('-2 minutes'),
            $now->modify('+1 month'),
            $now->modify('+1 month'),
        );
        $this->entityManager->flush();
        $this->goPayClient->reset();

        $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));

        // No GoPay call should be made — short-circuit fired.
        $this->assertSame(
            [],
            $this->goPayClient->getRecurrenceAmounts(),
            'Short-circuit must prevent any GoPay call when lastBilledAt is within 5 minutes.',
        );
    }

    public function testPollingTimeoutRecordsInFlightChargeInsteadOfFailing(): void
    {
        // Polling timed out — GoPay still says CREATED. The handler must NOT
        // throw (the webhook will reconcile) and must record the GoPay payment
        // ID as in-flight on the contract so the next cron run can reconcile
        // before issuing another charge. Critically, it must not bump
        // failedBillingAttempts — this is not (yet) a failure.
        $contract = $this->createContractWithRecurringPayment();
        $this->goPayClient->willStayPendingForRecurrence();

        $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Contract::class, $contract->id);

        $this->assertNotNull($refreshed->pendingRecurringPaymentId, 'Polling timeout must persist the GoPay payment ID for next-run reconciliation.');
        $this->assertNull($refreshed->lastBilledAt, 'lastBilledAt must NOT advance until the charge is confirmed.');
        $this->assertSame(0, $refreshed->failedBillingAttempts, 'A polling timeout is not a failure — webhook will reconcile.');
    }

    public function testReconcilesInFlightChargeOnNextRunWhenGoPayReportsPaid(): void
    {
        // Webhook never arrived (or arrived after the cron). On the next run,
        // the handler must check the in-flight payment's status, see PAID,
        // and reconcile billing dates inline — without issuing a new charge.
        $contract = $this->createContractWithRecurringPayment();
        $now = $this->clock->now();

        $contract->recordInFlightCharge('gp_inflight_paid');
        $this->goPayClient->seedRecurrenceStatus(
            paymentId: 'gp_inflight_paid',
            state: 'PAID',
            parentPaymentId: (string) $contract->goPayParentPaymentId,
            amount: 150_000,
        );
        $this->entityManager->flush();
        $this->goPayClient->reset();
        $this->goPayClient->seedRecurrenceStatus('gp_inflight_paid', 'PAID', (string) $contract->goPayParentPaymentId, 150_000);

        $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Contract::class, $contract->id);

        $this->assertNull($refreshed->pendingRecurringPaymentId, 'In-flight tracking must be cleared after inline reconciliation.');
        $this->assertNotNull($refreshed->lastBilledAt);
        $this->assertEquals($now->format('Y-m-d H:i:s'), $refreshed->lastBilledAt->format('Y-m-d H:i:s'));
        $this->assertSame(0, $refreshed->failedBillingAttempts);
        $this->assertSame([], $this->goPayClient->getRecurrenceAmounts(), 'No new charge must be issued — the in-flight one was reconciled.');
    }

    public function testSkipsChargeWhenInFlightPaymentIsStillPending(): void
    {
        // Previous attempt still being processed by GoPay (CREATED). Handler
        // must NOT charge again and must leave billing state untouched —
        // failedBillingAttempts stays 0, pendingRecurringPaymentId stays set.
        $contract = $this->createContractWithRecurringPayment();

        $contract->recordInFlightCharge('gp_inflight_pending');
        $this->goPayClient->seedRecurrenceStatus(
            paymentId: 'gp_inflight_pending',
            state: 'CREATED',
            parentPaymentId: (string) $contract->goPayParentPaymentId,
        );
        $this->entityManager->flush();

        $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));

        $this->entityManager->clear();
        $refreshed = $this->entityManager->find(Contract::class, $contract->id);

        $this->assertSame('gp_inflight_pending', $refreshed->pendingRecurringPaymentId);
        $this->assertNull($refreshed->lastBilledAt);
        $this->assertSame(0, $refreshed->failedBillingAttempts);
        $this->assertSame([], $this->goPayClient->getRecurrenceAmounts(), 'No new charge must be issued while a previous one is still pending.');
    }

    public function testThrowsAndClearsInFlightWhenGoPayReportsTerminalFailure(): void
    {
        // Previous attempt was canceled by GoPay (e.g. card declined after
        // 3DS). Handler must surface a PaymentNotConfirmedException so the
        // cron records a failed attempt — and must clear the in-flight ID
        // before throwing so the contract is no longer stuck waiting.
        $contract = $this->createContractWithRecurringPayment();

        $contract->recordInFlightCharge('gp_inflight_canceled');
        $this->goPayClient->seedRecurrenceStatus(
            paymentId: 'gp_inflight_canceled',
            state: 'CANCELED',
            parentPaymentId: (string) $contract->goPayParentPaymentId,
        );
        $this->entityManager->flush();

        $exceptionThrown = false;

        try {
            $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));
        } catch (\Symfony\Component\Messenger\Exception\HandlerFailedException $e) {
            $exceptionThrown = true;
            $previous = $e->getPrevious();
            $this->assertInstanceOf(\App\Service\GoPay\PaymentNotConfirmedException::class, $previous);
            $this->assertSame('CANCELED', $previous->state);
            $this->assertFalse($previous->isPending(), 'CANCELED must be classified as a terminal failure, not pending.');
        }

        $this->assertTrue($exceptionThrown, 'Terminal in-flight state must surface as PaymentNotConfirmedException.');
    }

    public function testFutureLastBilledAtDoesNotPermanentlyLockBilling(): void
    {
        // Defence against clock drift: if lastBilledAt somehow lies in the
        // future (data anomaly, container clock skew), the 5-minute guard
        // must NOT lock the contract out of billing forever.
        $contract = $this->createContractWithRecurringPayment();
        $now = $this->clock->now();

        $contract->recordBillingCharge(
            $now->modify('+10 minutes'),
            $now->modify('+1 month'),
            $now->modify('+1 month'),
        );
        $this->entityManager->flush();
        $this->goPayClient->reset();

        $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));

        $this->assertNotSame(
            [],
            $this->goPayClient->getRecurrenceAmounts(),
            'A future lastBilledAt must not silently skip the charge — the upper-bound guard should let it through.',
        );
    }

    private function createContractWithRecurringPayment(): Contract
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');

        // Create unlimited order
        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            RentalType::UNLIMITED,
            $startDate,
            null,
            $now,
            PaymentFrequency::MONTHLY,
        );

        // Accept terms before payment
        $order->acceptTerms($now);

        // Initiate payment
        $this->commandBus->dispatch(new InitiatePaymentCommand(
            order: $order,
            returnUrl: 'https://example.com/return',
            notificationUrl: 'https://example.com/webhook',
        ));

        // Simulate payment success
        $paymentId = $order->goPayPaymentId;
        \assert(null !== $paymentId);
        $this->goPayClient->simulatePaymentPaid($paymentId);

        // Process notification — auto-completes order and creates contract (terms accepted)
        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand($paymentId));

        $this->entityManager->flush();
        $this->entityManager->clear();

        // Fetch contract created by auto-completion
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.order = :order')
            ->setParameter('order', $order->id)
            ->getQuery()
            ->getSingleResult();

        return $contract;
    }
}
