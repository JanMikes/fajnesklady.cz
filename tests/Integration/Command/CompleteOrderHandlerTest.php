<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\CompleteOrderCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\OrderService;
use App\Service\PriceCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

class CompleteOrderHandlerTest extends KernelTestCase
{
    private OrderService $orderService;
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->orderService = $container->get(OrderService::class);
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->commandBus = $container->get('test.command.bus');
        $this->clock = $container->get(ClockInterface::class);
    }

    public function testFreeManualContractGetsNoBillingAnchor(): void
    {
        $order = $this->createPaidManualOrder();
        $order->setOnboardingBillingTerms(0, null);

        $contract = $this->completeViaBus($order);

        $this->assertTrue($contract->isFree());
        $this->assertNull($contract->nextBillingDate, 'free contracts must never enter the manual-billing track');
    }

    public function testExternallyPrepaidContractAnchorsBillingAfterPaidThrough(): void
    {
        $paidThrough = $this->clock->now()->modify('+5 days')->setTime(0, 0, 0);

        $order = $this->createPaidManualOrder();
        $order->setOnboardingBillingTerms(null, $paidThrough);

        $contract = $this->completeViaBus($order);

        $this->assertEquals($paidThrough, $contract->paidThroughDate);
        $this->assertEquals($paidThrough->modify('+1 day'), $contract->nextBillingDate);
    }

    /**
     * Production incident 2026-11-24 (order 01a20ea1): a yearly 12-month rental
     * paid by bank transfer a week before its start was anchored on the
     * payment moment — paid through 24.11.2027 with a 26 Kč stub cycle up to
     * the 01.12.2027 end. One yearly payment covers the whole term.
     */
    public function testYearlyRentalPaidBeforeStartIsPaidToTheEndWithNoAnchor(): void
    {
        // MockClock: 2025-06-15 12:00 — start a week later.
        $order = $this->createCustomerPaidOrder(
            startDate: new \DateTimeImmutable('2025-06-22'),
            endDate: new \DateTimeImmutable('2026-06-22'),
            frequency: PaymentFrequency::YEARLY,
            method: PaymentMethod::BANK_TRANSFER,
        );

        $contract = $this->completeViaBus($order);

        $this->assertNull($contract->nextBillingDate, 'nothing is left to bill');
        $this->assertSame('2026-06-22', $contract->paidThroughDate?->format('Y-m-d'));
        $this->assertTrue($contract->isPaidToEndOfTerm());
    }

    /**
     * Bank transfer paid 15.06 for a 01.07 start: periods follow the start
     * (01.08, 01.09, …), each is due on the 15th of the month before — the
     * first payment's day, like the card consent says for every type.
     */
    public function testBankTransferPaidBeforeStartIsDueOnThePaymentDayForStartAlignedPeriods(): void
    {
        $order = $this->createCustomerPaidOrder(
            startDate: new \DateTimeImmutable('2025-07-01'),
            endDate: new \DateTimeImmutable('2026-01-01'),
            frequency: PaymentFrequency::MONTHLY,
            method: PaymentMethod::BANK_TRANSFER,
        );

        $contract = $this->completeViaBus($order);

        $this->assertSame('2025-07-15', $contract->nextBillingDate?->format('Y-m-d'), 'due on the 15th');
        $this->assertSame('2025-08-01', $contract->nextPeriodStart()?->format('Y-m-d'), 'pays the period from 01.08');
        $this->assertSame('2025-08-01', $contract->paidThroughDate?->format('Y-m-d'));
        $this->assertSame('2025-07-31', $contract->lastPaidDay()?->format('Y-m-d'));
    }

    /**
     * Card consent: "vždy ke stejnému dni v měsíci jako první platba" — the
     * card is charged on the 15th, each charge paying the next start-aligned
     * period.
     */
    public function testCardPaidBeforeStartIsChargedOnThePaymentDay(): void
    {
        $order = $this->createCustomerPaidOrder(
            startDate: new \DateTimeImmutable('2025-07-01'),
            endDate: new \DateTimeImmutable('2026-01-01'),
            frequency: PaymentFrequency::MONTHLY,
            method: PaymentMethod::GOPAY,
            goPayParentPaymentId: 'gp_parent_123',
        );

        $contract = $this->completeViaBus($order);

        $this->assertSame('gp_parent_123', $contract->goPayParentPaymentId);
        $this->assertSame('2025-07-15', $contract->nextBillingDate?->format('Y-m-d'), 'MockClock pays on 15.06');
        $this->assertSame('2025-08-01', $contract->nextPeriodStart()?->format('Y-m-d'));
    }

    /**
     * Owner decision 2026-11-25: a payment arriving after the start (admin
     * onboarding) still pays the quoted first period — the days before the
     * payment are not free; later periods are due on the payment's day.
     */
    public function testPaymentAfterStartPaysTheFirstPeriodAndIsDueOnThePaymentDay(): void
    {
        $order = $this->createCustomerPaidOrder(
            startDate: new \DateTimeImmutable('2025-06-05'),
            endDate: new \DateTimeImmutable('2025-12-05'),
            frequency: PaymentFrequency::MONTHLY,
            method: PaymentMethod::BANK_TRANSFER,
        );

        $contract = $this->completeViaBus($order);

        $this->assertSame('2025-07-05', $contract->nextPeriodStart()?->format('Y-m-d'));
        $this->assertSame('2025-07-15', $contract->nextBillingDate?->format('Y-m-d'));
    }

    public function testPaymentAWholeCycleLateFallsBackToPaymentDateAnchor(): void
    {
        $order = $this->createCustomerPaidOrder(
            startDate: new \DateTimeImmutable('2025-05-01'),
            endDate: new \DateTimeImmutable('2025-12-01'),
            frequency: PaymentFrequency::MONTHLY,
            method: PaymentMethod::BANK_TRANSFER,
        );

        $contract = $this->completeViaBus($order);

        // 01.06 (start + 1 month) already passed — never anchor in the past.
        $this->assertSame('2025-07-15', $contract->nextBillingDate?->format('Y-m-d'));
        $this->assertNull($contract->nextPeriodStart, 'single walk');
    }

    /**
     * Through the real handler: the contract pays exactly the schedule the
     * customer was quoted — every period and amount — whatever the start day,
     * length or cadence, each later payment due on the first payment's day.
     */
    #[DataProvider('scheduleCases')]
    public function testContractPaysExactlyTheQuotedSchedule(string $start, string $end, PaymentFrequency $frequency): void
    {
        $order = $this->createCustomerPaidOrder(
            startDate: new \DateTimeImmutable($start),
            endDate: new \DateTimeImmutable($end),
            frequency: $frequency,
            method: PaymentMethod::BANK_TRANSFER,
        );
        $schedule = static::getContainer()->get(PriceCalculator::class)->buildScheduleFromOrder($order);

        $contract = $this->completeViaBus($order);

        $calculator = static::getContainer()->get(RecurringAmountCalculator::class);
        foreach (array_slice($schedule->entries, 1) as $i => $entry) {
            $due = $contract->nextBillingDate;
            $this->assertNotNull($due, sprintf('cycle %d is missing on the contract', $i + 1));
            $this->assertSame($entry->chargeDate->format('Y-m-d'), $contract->nextPeriodStart()?->format('Y-m-d'), sprintf('cycle %d period', $i + 1));
            $this->assertSame($entry->amount, $calculator->calculate($contract, $due), sprintf('cycle %d amount', $i + 1));
            $contract->recordCyclePaid($due);
        }
        $this->assertNull($contract->nextBillingDate, 'the contract bills no cycle the schedule does not have');
        $this->assertSame((new \DateTimeImmutable($end))->format('Y-m-d'), $contract->paidThroughDate?->format('Y-m-d'));
    }

    /**
     * @return iterable<string, array{string, string, PaymentFrequency}>
     */
    public static function scheduleCases(): iterable
    {
        yield 'monthly, starts in a week' => ['2025-06-22', '2025-12-22', PaymentFrequency::MONTHLY];
        yield 'monthly, starts today' => ['2025-06-15', '2026-06-15', PaymentFrequency::MONTHLY];
        yield 'monthly, prorated tail' => ['2025-07-01', '2025-09-15', PaymentFrequency::MONTHLY];
        yield 'monthly, single cycle' => ['2025-07-01', '2025-08-01', PaymentFrequency::MONTHLY];
        yield 'monthly, start on the 31st' => ['2025-07-31', '2026-01-31', PaymentFrequency::MONTHLY];
        yield 'monthly, started 3 days ago' => ['2025-06-12', '2025-12-12', PaymentFrequency::MONTHLY];
        yield 'yearly, whole term' => ['2025-07-01', '2026-07-01', PaymentFrequency::YEARLY];
        yield 'yearly, 13 months' => ['2025-07-01', '2026-08-01', PaymentFrequency::YEARLY];
    }

    public function testWholeTermExternalPrepaymentIsNotReseeded(): void
    {
        $order = $this->createPaidManualOrder();
        \assert(null !== $order->endDate);
        $order->setOnboardingBillingTerms(null, $order->endDate);

        $contract = $this->completeViaBus($order);

        $this->assertNull($contract->nextBillingDate, 'a fully prepaid customer must never be billed');
        $this->assertTrue($contract->isPaidToEndOfTerm());
    }

    private function createCustomerPaidOrder(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        PaymentFrequency $frequency,
        PaymentMethod $method,
        ?string $goPayParentPaymentId = null,
    ): Order {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $this->clock->now();
        $order = $this->orderService->createOrder(
            user: $tenant,
            storageType: $storageType,
            place: $place,
            startDate: $startDate,
            endDate: $endDate,
            now: $now,
            paymentFrequency: $frequency,
        );
        $order->setPaymentMethod($method);
        $order->setBillingMode(null !== $goPayParentPaymentId ? BillingMode::AUTO_RECURRING : BillingMode::MANUAL_RECURRING);
        if (null !== $goPayParentPaymentId) {
            $order->setGoPayParentPaymentId($goPayParentPaymentId);
        }

        $order->reserve($now);
        $this->orderService->processPayment($order);
        $this->orderService->confirmPayment($order, $now);

        return $order;
    }

    private function createPaidManualOrder(): Order
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box']);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');

        $order = $this->orderService->createOrder(
            user: $tenant,
            storageType: $storageType,
            place: $place,
            startDate: $startDate,
            endDate: $startDate->modify('+12 months'),
            now: $now,
        );
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->setPaymentMethod(PaymentMethod::EXTERNAL);
        $order->markAsAdminCreated();

        $order->reserve($now);
        $this->orderService->processPayment($order);
        $this->orderService->confirmPayment($order, $now);

        return $order;
    }

    private function completeViaBus(Order $order): Contract
    {
        $envelope = $this->commandBus->dispatch(new CompleteOrderCommand(order: $order));
        $handledStamp = $envelope->last(HandledStamp::class);
        \assert(null !== $handledStamp);
        $contract = $handledStamp->getResult();
        \assert($contract instanceof Contract);

        return $contract;
    }
}
