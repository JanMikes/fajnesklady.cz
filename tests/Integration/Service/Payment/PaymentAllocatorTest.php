<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Payment;

use App\DataFixtures\UserFixtures;
use App\Entity\BankTransaction;
use App\Entity\BankTransactionAllocation;
use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\AllocationStepType;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Repository\BankTransactionAllocationRepository;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\Identity\ProvideIdentity;
use App\Service\OrderService;
use App\Service\Payment\PaymentAllocator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The allocation waterfall is where money decides what it pays for, so it gets
 * the heaviest coverage in spec 091. Run against real entities and a real
 * database rather than doubles: `hasUnpaidDebt()`, `usesManualBillingTrack()`
 * and the pricing all have real behaviour worth exercising, and stubbing them
 * would let the test agree with a lie.
 */
final class PaymentAllocatorTest extends KernelTestCase
{
    private const int DEBT = 150_000;

    private EntityManagerInterface $entityManager;
    private PaymentAllocator $allocator;
    private RecurringAmountCalculator $amountCalculator;
    private OrderService $orderService;
    private ProvideIdentity $identityProvider;
    private ClockInterface $clock;
    private BankTransactionAllocationRepository $allocationRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->allocator = $container->get(PaymentAllocator::class);
        $this->amountCalculator = $container->get(RecurringAmountCalculator::class);
        $this->orderService = $container->get(OrderService::class);
        $this->identityProvider = $container->get(ProvideIdentity::class);
        $this->clock = $container->get(ClockInterface::class);
        $this->allocationRepository = $container->get(BankTransactionAllocationRepository::class);
    }

    /**
     * The regression test for the reordering. The old cascade returned
     * unconditionally from the manual-billing branch, so a customer who owed a
     * debt AND had an active cycle had every transfer eaten by the rental while
     * the debt was never touched.
     */
    public function testDebtIsSettledBeforeTheCycle(): void
    {
        [$order, $contract] = $this->orderWithManualContract(debt: self::DEBT);

        $plan = $this->allocator->plan($order, $contract, self::DEBT, $this->clock->now());

        $debtStep = $plan->step(AllocationStepType::ONBOARDING_DEBT);
        self::assertNotNull($debtStep, 'Debt step missing entirely.');
        self::assertSame(self::DEBT, $debtStep->allocated);
        self::assertTrue($debtStep->fullySettled);

        $cycleStep = $plan->step(AllocationStepType::BILLING_CYCLE);
        self::assertNotNull($cycleStep);
        self::assertSame(0, $cycleStep->allocated, 'Nothing should have reached the cycle.');
        self::assertFalse($cycleStep->fullySettled);
    }

    public function testExactCyclePaymentSettlesItAndLeavesNoCredit(): void
    {
        [$order, $contract] = $this->orderWithManualContract();
        $cycle = $this->cycleAmount($contract);

        $plan = $this->allocator->plan($order, $contract, $cycle, $this->clock->now());

        self::assertTrue($plan->step(AllocationStepType::BILLING_CYCLE)?->fullySettled);
        self::assertSame(0, $plan->creditAdded());
        self::assertTrue($plan->settlesEverything());
    }

    public function testUnderPaymentLeavesTheCycleUnsettled(): void
    {
        [$order, $contract] = $this->orderWithManualContract();
        $cycle = $this->cycleAmount($contract);

        $plan = $this->allocator->plan($order, $contract, intdiv($cycle, 2), $this->clock->now());

        $cycleStep = $plan->step(AllocationStepType::BILLING_CYCLE);
        self::assertNotNull($cycleStep);
        self::assertFalse($cycleStep->fullySettled, 'Half a cycle is not a paid cycle.');
        self::assertFalse($plan->settlesEverything());

        // The money must land in credit, not be swallowed by the unsettled cycle.
        // Cycle allocations are never summed back, so anything "allocated" to an
        // unpaid cycle would simply vanish.
        self::assertSame(0, $cycleStep->allocated);
        self::assertSame(intdiv($cycle, 2), $plan->creditAdded());
    }

    /**
     * End-to-end money conservation across two under-payments: nothing may be
     * lost between them, and the cycle settles exactly once, on the second.
     */
    public function testTwoUnderPaymentsConserveMoneyAndSettleTheCycleOnce(): void
    {
        [$order, $contract] = $this->orderWithManualContract();
        $cycle = $this->cycleAmount($contract);
        $first = intdiv($cycle, 2);
        $second = $cycle - $first;
        $now = $this->clock->now();

        $planA = $this->allocator->plan($order, $contract, $first, $now);
        self::assertFalse($planA->step(AllocationStepType::BILLING_CYCLE)?->fullySettled);
        $this->allocator->apply($planA, $this->transaction($first), $order, $contract, $now);
        $this->entityManager->flush();

        self::assertSame($first, $contract->creditBalance, 'The first under-payment must be held as credit.');

        $planB = $this->allocator->plan($order, $contract, $second, $now);
        self::assertTrue($planB->step(AllocationStepType::BILLING_CYCLE)?->fullySettled, 'Together they cover the cycle.');
        $this->allocator->apply($planB, $this->transaction($second), $order, $contract, $now);
        $this->entityManager->flush();

        self::assertSame(0, $contract->creditBalance, 'Credit is fully consumed by the settled cycle.');
    }

    /**
     * The case tryAccumulatePartialPayments() used to handle, now via credit.
     */
    public function testFollowUpPaymentDrainsCreditAndSettlesTheCycle(): void
    {
        [$order, $contract] = $this->orderWithManualContract();
        $cycle = $this->cycleAmount($contract);

        // Split the cycle so neither half depends on fixture pricing.
        $shortfall = intdiv($cycle, 3);
        $contract->addCredit($cycle - $shortfall);
        $this->entityManager->flush();

        $plan = $this->allocator->plan($order, $contract, $shortfall, $this->clock->now());

        self::assertSame($cycle, $plan->available);
        self::assertSame($cycle - $shortfall, $plan->creditUsed);
        self::assertTrue($plan->step(AllocationStepType::BILLING_CYCLE)?->fullySettled);
        self::assertSame(0, $plan->creditAdded(), 'Credit should be fully consumed.');
    }

    public function testOverPaymentSettlesTheCycleAndCreditsTheSurplus(): void
    {
        [$order, $contract] = $this->orderWithManualContract();
        $cycle = $this->cycleAmount($contract);

        $plan = $this->allocator->plan($order, $contract, $cycle + 40_000, $this->clock->now());

        self::assertTrue($plan->step(AllocationStepType::BILLING_CYCLE)?->fullySettled);
        self::assertSame(40_000, $plan->creditAdded());
    }

    public function testWaterfallSpillsAcrossDebtAndCycleAndCreditsTheRest(): void
    {
        [$order, $contract] = $this->orderWithManualContract(debt: self::DEBT);
        $cycle = $this->cycleAmount($contract);

        $plan = $this->allocator->plan($order, $contract, self::DEBT + $cycle + 40_000, $this->clock->now());

        self::assertTrue($plan->step(AllocationStepType::ONBOARDING_DEBT)?->fullySettled);
        self::assertTrue($plan->step(AllocationStepType::BILLING_CYCLE)?->fullySettled);
        self::assertSame(40_000, $plan->creditAdded());
        self::assertTrue($plan->settlesEverything());
    }

    public function testContractDebtIsSettledBeforeTheCycle(): void
    {
        [$order, $contract] = $this->orderWithManualContract();
        $contract->setOutstandingDebt(90_000);
        $this->entityManager->flush();

        $plan = $this->allocator->plan($order, $contract, 90_000, $this->clock->now());

        self::assertTrue($plan->step(AllocationStepType::CONTRACT_DEBT)?->fullySettled);
        self::assertSame(0, $plan->step(AllocationStepType::BILLING_CYCLE)?->allocated);
    }

    public function testSurplusOnAnOrderWithNoContractIsReportedNotSwallowed(): void
    {
        $order = $this->payableOrder();

        $plan = $this->allocator->plan($order, null, $order->firstPaymentPrice + 50_000, $this->clock->now());

        self::assertTrue($plan->step(AllocationStepType::FIRST_PAYMENT)?->fullySettled);
        self::assertSame(50_000, $plan->unallocated, 'No contract means nowhere to hold credit.');
        self::assertSame(0, $plan->creditAdded());
    }

    public function testPartialFirstPaymentAccumulatesAcrossTransfers(): void
    {
        $order = $this->payableOrder();
        $already = intdiv($order->firstPaymentPrice, 3);
        $this->recordAllocation($order, AllocationStepType::FIRST_PAYMENT, $already);

        $plan = $this->allocator->plan($order, null, $order->firstPaymentPrice - $already, $this->clock->now());

        $step = $plan->step(AllocationStepType::FIRST_PAYMENT);
        self::assertNotNull($step);
        self::assertSame($already, $step->previouslyPaid);
        self::assertSame($order->firstPaymentPrice - $already, $step->expected, 'Only the remainder is still owed.');
        self::assertTrue($step->fullySettled);
    }

    /**
     * Spec 091 D2 — the double-count found reviewing 089. Money already applied
     * to the DEBT must not reduce what is owed on the FIRST PAYMENT.
     */
    public function testDebtAllocationsDoNotDiscountTheFirstPayment(): void
    {
        $order = $this->payableOrder();
        $this->recordAllocation($order, AllocationStepType::ONBOARDING_DEBT, $order->firstPaymentPrice);

        $plan = $this->allocator->plan($order, null, 10_000, $this->clock->now());

        $step = $plan->step(AllocationStepType::FIRST_PAYMENT);
        self::assertNotNull($step);
        self::assertSame(0, $step->previouslyPaid, 'Debt money is a different pool.');
        self::assertSame($order->firstPaymentPrice, $step->expected);
        self::assertFalse($step->fullySettled);
    }

    /**
     * Spec 091 D1 — a card order's first payment can never be settled by wire.
     */
    public function testCardOrderFirstPaymentIsNotAllocatable(): void
    {
        $order = $this->payableOrder(billingMode: BillingMode::AUTO_RECURRING);

        self::assertTrue($this->allocator->isFirstPaymentBlockedForCard($order, null));

        $plan = $this->allocator->plan($order, null, $order->firstPaymentPrice, $this->clock->now());

        self::assertNull($plan->step(AllocationStepType::FIRST_PAYMENT));
        self::assertSame($order->firstPaymentPrice, $plan->unallocated);
    }

    public function testPlanIsPureAndMutatesNothing(): void
    {
        [$order, $contract] = $this->orderWithManualContract(debt: self::DEBT);
        $contract->addCredit(50_000);
        $contract->setOutstandingDebt(20_000);
        $this->entityManager->flush();

        $this->allocator->plan($order, $contract, 500_000, $this->clock->now());
        $this->allocator->plan($order, $contract, 500_000, $this->clock->now());

        self::assertSame(50_000, $contract->creditBalance);
        self::assertSame(20_000, $contract->outstandingDebtAmount);
        self::assertSame(self::DEBT, $order->onboardingDebtInHaler);
        self::assertNull($order->debtPaidAt);
    }

    public function testApplyMovesCreditAndRecordsAllocations(): void
    {
        [$order, $contract] = $this->orderWithManualContract();
        $cycle = $this->cycleAmount($contract);
        $now = $this->clock->now();

        $plan = $this->allocator->plan($order, $contract, $cycle + 40_000, $now);
        $tx = $this->transaction($cycle + 40_000);

        $this->allocator->apply($plan, $tx, $order, $contract, $now);
        $this->entityManager->flush();

        self::assertSame(40_000, $contract->creditBalance, 'Surplus should be credited forward.');

        $allocations = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(BankTransactionAllocation::class, 'a')
            ->where('a.order = :order')
            ->setParameter('order', $order)
            ->getQuery()
            ->getResult();

        self::assertCount(1, $allocations, 'Only the cycle is a recorded obligation; credit is not.');
        self::assertSame(AllocationStepType::BILLING_CYCLE, $allocations[0]->type);
        self::assertSame($cycle, $allocations[0]->amountInHaler);
    }

    /**
     * Review finding: replaying a partially-allocated transaction must not count
     * its own earlier contribution twice. The pairing handler releases the
     * transaction's prior allocations before re-planning; this proves the
     * allocator agrees once they are gone.
     */
    public function testReleasingATransactionsAllocationsRestoresTheFullObligation(): void
    {
        $order = $this->payableOrder();
        $part = intdiv($order->firstPaymentPrice, 2);
        $tx = $this->transaction($part);
        $this->entityManager->flush();

        $plan = $this->allocator->plan($order, null, $part, $this->clock->now());
        $this->allocator->apply($plan, $tx, $order, null, $this->clock->now());
        $this->entityManager->flush();

        // Replaying the same transfer without releasing would see its own money as
        // "already paid" and settle the rest for free.
        $stale = $this->allocator->plan($order, null, $part, $this->clock->now());
        self::assertSame($order->firstPaymentPrice - $part, $stale->step(AllocationStepType::FIRST_PAYMENT)?->expected);

        $deleted = $this->allocationRepository->deleteForTransaction($tx);
        self::assertSame(1, $deleted);

        $fresh = $this->allocator->plan($order, null, $part, $this->clock->now());
        self::assertSame(
            $order->firstPaymentPrice,
            $fresh->step(AllocationStepType::FIRST_PAYMENT)?->expected,
            'Once released, the full obligation is owed again.',
        );
        self::assertFalse($fresh->step(AllocationStepType::FIRST_PAYMENT)?->fullySettled);
    }

    /**
     * Review finding: a first payment completed by a SECOND transfer must book a
     * Payment for the whole rental, not just the completing remainder.
     */
    public function testCompletedFirstPaymentDispatchesTheFullPriceNotTheRemainder(): void
    {
        $order = $this->payableOrder();
        $already = intdiv($order->firstPaymentPrice, 3);
        $this->recordAllocation($order, AllocationStepType::FIRST_PAYMENT, $already);

        $remainder = $order->firstPaymentPrice - $already;
        $plan = $this->allocator->plan($order, null, $remainder, $this->clock->now());

        $step = $plan->step(AllocationStepType::FIRST_PAYMENT);
        self::assertNotNull($step);
        self::assertTrue($step->fullySettled);
        // The step tracks the remainder...
        self::assertSame($remainder, $step->expected);
        // ...but the Payment booked must be the whole rental. apply() passes
        // $order->firstPaymentPrice for FIRST_PAYMENT precisely for this reason.
        self::assertGreaterThan($step->expected, $order->firstPaymentPrice);
    }

    /**
     * Spec 089's purpose must survive D1: a CARD order that owes an onboarding
     * debt can still settle that debt by wire — only its first payment is refused.
     */
    public function testCardOrderCanStillSettleItsDebtByTransfer(): void
    {
        $order = $this->payableOrder(billingMode: BillingMode::AUTO_RECURRING);
        $order->setOnboardingDebt(self::DEBT);
        $this->entityManager->flush();

        $plan = $this->allocator->plan($order, null, self::DEBT, $this->clock->now());

        self::assertTrue($plan->step(AllocationStepType::ONBOARDING_DEBT)?->fullySettled, 'The debt must still be payable.');
        self::assertNull($plan->step(AllocationStepType::FIRST_PAYMENT), 'The first payment stays refused.');
        self::assertNotSame([], $plan->obligationSteps(), 'So the pairing guard must not refuse the whole transfer.');
    }

    private function cycleAmount(Contract $contract): int
    {
        return $this->amountCalculator->calculate($contract, $this->clock->now());
    }

    private function recordAllocation(Order $order, AllocationStepType $type, int $amount): void
    {
        $this->entityManager->persist(new BankTransactionAllocation(
            id: $this->identityProvider->next(),
            bankTransaction: $this->transaction($amount),
            order: $order,
            type: $type,
            amountInHaler: $amount,
            createdAt: $this->clock->now(),
        ));
        $this->entityManager->flush();
    }

    private function transaction(int $amount): BankTransaction
    {
        $tx = new BankTransaction(
            id: $this->identityProvider->next(),
            fioTransactionId: 'fio-alloc-'.bin2hex(random_bytes(6)),
            amount: $amount,
            currency: 'CZK',
            variableSymbol: null,
            senderAccountNumber: '123456789/0800',
            senderName: 'Jan Testovací',
            transactionDate: $this->clock->now(),
            comment: null,
            createdAt: $this->clock->now(),
        );
        $this->entityManager->persist($tx);

        return $tx;
    }

    private function payableOrder(BillingMode $billingMode = BillingMode::MANUAL_RECURRING): Order
    {
        $order = $this->buildOrder();
        $order->acceptTerms($this->clock->now());
        $order->setPaymentMethod(BillingMode::AUTO_RECURRING === $billingMode ? PaymentMethod::GOPAY : PaymentMethod::BANK_TRANSFER);
        $order->setBillingMode($billingMode);
        $this->entityManager->flush();

        self::assertTrue($order->canBePaid());

        return $order;
    }

    /**
     * @return array{Order, Contract}
     */
    private function orderWithManualContract(?int $debt = null): array
    {
        $order = $this->buildOrder();
        $order->acceptTerms($this->clock->now());
        $order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);

        if (null !== $debt) {
            $order->setOnboardingDebt($debt);
        }

        $now = $this->clock->now();
        $contract = new Contract(
            id: $this->identityProvider->next(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            startDate: $order->startDate,
            endDate: $order->endDate ?? $order->startDate->modify('+1 year'),
            createdAt: $now,
        );
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->scheduleNextBilling($now->modify('+1 month'), null);

        $this->entityManager->persist($contract);
        $this->entityManager->flush();

        self::assertTrue($contract->usesManualBillingTrack());

        return [$order, $contract];
    }

    private function buildOrder(): Order
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box', 'place' => $place]);

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');

        return $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $startDate,
            $startDate->modify('+12 months'),
            $now,
            PaymentFrequency::MONTHLY,
        );
    }
}
