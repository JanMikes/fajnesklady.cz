<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ProcessPaymentNotificationCommand;
use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\Identity\ProvideIdentity;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 077 — webhook branch completing the prolongation bank→card switch.
 */
final class ProcessPaymentNotificationHandlerCardSetupTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
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
        $this->clock = $container->get(ClockInterface::class);

        /** @var MockGoPayClient $goPayClient */
        $goPayClient = $container->get(MockGoPayClient::class);
        $this->goPayClient = $goPayClient;
        $this->goPayClient->reset();
    }

    public function testPaidSetupChargeEstablishesTokenAndPaysNextCycle(): void
    {
        $contract = $this->createManualContractWithPendingSetup('gp_card_setup_paid');
        $originalNextBilling = $contract->nextBillingDate;
        self::assertNotNull($originalNextBilling);

        $this->goPayClient->seedRecurrenceStatus('gp_card_setup_paid', 'PAID', '', 50000);

        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand('gp_card_setup_paid'));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);

        self::assertSame(BillingMode::AUTO_RECURRING, $reloaded->billingMode);
        self::assertSame('gp_card_setup_paid', $reloaded->goPayParentPaymentId);
        self::assertNull($reloaded->pendingCardSetupPaymentId);
        self::assertSame(PaymentMethod::GOPAY, $reloaded->order->paymentMethod);
        self::assertEquals(
            $originalNextBilling->modify('+1 month')->format('Y-m-d'),
            $reloaded->nextBillingDate?->format('Y-m-d'),
            'The setup charge pays the next cycle, so billing advances one cadence step.',
        );

        // The RecurringPaymentCharged fan-out must have produced a Payment row
        // (the top-of-handler idempotency backstop for duplicate webhooks).
        $payment = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Payment::class, 'p')
            ->where('p.goPayPaymentId = :id')
            ->setParameter('id', 'gp_card_setup_paid')
            ->getQuery()
            ->getOneOrNullResult();
        self::assertNotNull($payment);
    }

    public function testPaidSetupChargeSettlesOutstandingManualRequest(): void
    {
        // Double-charge guard: the reminder cron already opened a manual
        // request for the current cycle — the card setup pays that cycle, so
        // the request must be settled and never chased by bank transfer again.
        $contract = $this->createManualContractWithPendingSetup('gp_card_setup_settles');
        $nextBilling = $contract->nextBillingDate;
        self::assertNotNull($nextBilling);

        $identity = static::getContainer()->get(ProvideIdentity::class);
        $request = new ManualPaymentRequest(
            id: $identity->next(),
            contract: $contract,
            periodStart: $nextBilling,
            periodEnd: $nextBilling->modify('+1 month'),
            amount: 50000,
            createdAt: $this->clock->now(),
        );
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $this->goPayClient->seedRecurrenceStatus('gp_card_setup_settles', 'PAID', '', 50000);

        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand('gp_card_setup_settles'));

        $this->entityManager->clear();
        $reloadedRequest = $this->entityManager->find(ManualPaymentRequest::class, $request->id);
        self::assertNotNull($reloadedRequest);
        self::assertTrue($reloadedRequest->isPaid(), 'The card setup settles the cycle the manual request covers.');
    }

    public function testCancelledSetupChargeLeavesContractOnManualTrack(): void
    {
        $contract = $this->createManualContractWithPendingSetup('gp_card_setup_cancelled');

        $this->goPayClient->seedRecurrenceStatus('gp_card_setup_cancelled', 'CANCELED', '', 50000);

        $this->commandBus->dispatch(new ProcessPaymentNotificationCommand('gp_card_setup_cancelled'));

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);

        self::assertSame(BillingMode::MANUAL_RECURRING, $reloaded->billingMode);
        self::assertNull($reloaded->goPayParentPaymentId);
        self::assertNull($reloaded->pendingCardSetupPaymentId, 'Terminal status must clear the pending marker.');
    }

    private function createManualContractWithPendingSetup(string $paymentId): Contract
    {
        $now = $this->clock->now();
        $startDate = $now->modify('-30 days');
        $endDate = $now->modify('+150 days');
        $user = $this->findUser('user@example.com');
        $storage = $this->findStorage('A3');

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: 50000,
            expiresAt: $startDate->modify('+7 days'),
            createdAt: $startDate,
        );
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        $order->assignVariableSymbol('9999000077');
        $order->markPaid($startDate);
        $order->popEvents();
        $this->entityManager->persist($order);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: $startDate,
        );
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->sign($startDate);
        $contract->scheduleNextBilling($now->modify('+10 days'), $now->modify('+10 days'));
        $contract->startCardSetup($paymentId);
        $order->complete($contract->id, $startDate);
        $order->popEvents();
        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        return $contract;
    }

    private function findUser(string $email): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($user instanceof User);

        return $user;
    }

    private function findStorage(string $number): Storage
    {
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.number = :number')
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($storage instanceof Storage);

        return $storage;
    }
}
