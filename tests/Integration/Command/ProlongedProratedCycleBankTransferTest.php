<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ProcessIncomingBankTransactionCommand;
use App\Command\ProlongContractCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\BankTransaction;
use App\Entity\BankTransactionAllocation;
use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Place;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\Identity\ProvideIdentity;
use App\Service\OrderService;
use App\Value\FioBankTransaction;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Production incident 2026-11-03 (order 019f98cd). The last cycle of a
 * fixed-term bank-transfer contract was requested as a prorated amount up to
 * the end date. The tenant then prolonged the contract and paid exactly the
 * requested amount. The matcher recomputed the cycle against the new end date
 * (a full month), declared an under-payment, parked the money as credit and
 * left the request overdue.
 *
 * Correct behaviour: the requested amount settles the requested period, and
 * billing resumes at that period's end — the prolongation applies from the
 * next cycle.
 */
class ProlongedProratedCycleBankTransferTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private OrderService $orderService;
    private RecurringAmountCalculator $amountCalculator;
    private ProvideIdentity $identity;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->commandBus = $container->get('test.command.bus');
        $this->orderService = $container->get(OrderService::class);
        $this->amountCalculator = $container->get(RecurringAmountCalculator::class);
        $this->identity = $container->get(ProvideIdentity::class);
        $this->clock = $container->get(ClockInterface::class);
    }

    public function testPayingTheRequestedProratedAmountAfterProlongationSettlesThatPeriod(): void
    {
        $now = $this->clock->now();
        $contract = $this->createManualContract();
        $oldEndDate = $contract->endDate;

        // Anchor 24 days before the end, with a time of day like a real anchor.
        $anchor = $oldEndDate->modify('-24 days')->setTime(15, 15, 48);
        $contract->scheduleNextBilling($anchor, $anchor);
        $this->entityManager->flush();

        $fullCycle = $contract->getEffectiveRecurringAmount();
        $requested = $this->amountCalculator->calculate($contract, $now);
        self::assertLessThan($fullCycle, $requested, 'Precondition: the last cycle is prorated.');

        $request = new ManualPaymentRequest(
            id: $this->identity->next(),
            contract: $contract,
            periodStart: $anchor->setTime(0, 0),
            periodEnd: $oldEndDate,
            amount: $requested,
            createdAt: $now,
        );
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $this->commandBus->dispatch(new ProlongContractCommand(
            contractId: $contract->id,
            newEndDate: $oldEndDate->modify('+6 months'),
            switchTo: null,
            actorId: null,
        ));
        self::assertNotSame($requested, $this->amountCalculator->calculate($contract, $now), 'Precondition: prolongation re-prices a fresh calculation.');

        $variableSymbol = $contract->order->variableSymbol;
        self::assertNotNull($variableSymbol);

        $this->commandBus->dispatch(new ProcessIncomingBankTransactionCommand(new FioBankTransaction(
            id: 'fio-test-prolonged-prorated-1',
            amount: $requested,
            currency: 'CZK',
            variableSymbol: $variableSymbol,
            senderAccountNumber: '1583974193/0800',
            senderName: 'Jan Testovací',
            date: $now,
            comment: null,
        )));

        $this->entityManager->clear();

        $bankTx = $this->entityManager->getRepository(BankTransaction::class)
            ->findOneBy(['fioTransactionId' => 'fio-test-prolonged-prorated-1']);
        self::assertInstanceOf(BankTransaction::class, $bankTx);
        self::assertTrue($bankTx->isMatched(), 'Paying exactly the requested amount is not a mismatch.');

        $reloadedRequest = $this->entityManager->find(ManualPaymentRequest::class, $request->id);
        self::assertNotNull($reloadedRequest);
        self::assertTrue($reloadedRequest->isPaid());

        $reloadedContract = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloadedContract);
        self::assertSame(0, $reloadedContract->creditBalance, 'Nothing may be parked as credit.');
        self::assertSame($oldEndDate->format('Y-m-d'), $reloadedContract->nextBillingDate?->format('Y-m-d'), 'The next cycle starts where the paid one ended.');
        self::assertSame($oldEndDate->format('Y-m-d'), $reloadedContract->paidThroughDate?->format('Y-m-d'));

        $payments = $this->entityManager->getRepository(Payment::class)->findBy(['bankTransaction' => $bankTx]);
        self::assertCount(1, $payments);
        self::assertSame($requested, $payments[0]->amount);

        $allocations = $this->entityManager->getRepository(BankTransactionAllocation::class)->findBy(['bankTransaction' => $bankTx]);
        self::assertCount(1, $allocations);
    }

    private function createManualContract(): Contract
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box', 'place' => $place]);

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $startDate,
            $now->modify('+3 months'),
            $now,
            PaymentFrequency::MONTHLY,
        );
        $order->setPaymentMethod(PaymentMethod::EXTERNAL);
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->setOnboardingBillingTerms(
            individualMonthlyAmount: null,
            paidThroughDate: $now->modify('+1 month'),
        );
        $order->assignVariableSymbol('9812340099');
        $order->markPaid($now);

        $contract = $this->orderService->completeOrder($order, $now);

        $this->entityManager->flush();

        return $contract;
    }
}
