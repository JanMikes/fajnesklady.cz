<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ProcessIncomingBankTransactionCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\BankTransaction;
use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Entity\Order;
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
 * Regression for the prepaid-onboarding pairing bug: an externally-prepaid
 * onboarding order carries `paymentMethod = EXTERNAL` yet runs its later cycles
 * on the MANUAL_RECURRING bank-transfer track. When the customer pays the next
 * cycle by bank transfer (correct VS + amount), the VS auto-matcher must
 * reconcile it against the contract and mark the pending payment request paid —
 * it previously skipped the reconcile branch because the order was not
 * BANK_TRANSFER, leaving the transaction `unmatched` and the contract un-prolonged.
 */
class ProcessIncomingBankTransactionExternalManualBillingTest extends KernelTestCase
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

    public function testExternalPrepaidOrderPairsBankTransferAndProlongsContract(): void
    {
        $now = $this->clock->now();
        $contract = $this->createExternalPrepaidManualContract();

        $billingPeriodStart = $contract->nextBillingDate;
        self::assertNotNull($billingPeriodStart, 'prepaid contract must have a live billing anchor');

        $expectedAmount = $this->amountCalculator->calculate($contract, $now);

        // The manual-billing cron already e-mailed a bank-transfer request for
        // the upcoming cycle; it is still pending when the money arrives.
        $request = new ManualPaymentRequest(
            id: $this->identity->next(),
            contract: $contract,
            periodStart: $billingPeriodStart,
            periodEnd: $billingPeriodStart->modify('+1 month'),
            amount: $expectedAmount,
            createdAt: $now,
        );
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        $variableSymbol = $contract->order->variableSymbol;
        self::assertNotNull($variableSymbol);

        $this->commandBus->dispatch(new ProcessIncomingBankTransactionCommand(new FioBankTransaction(
            id: 'fio-test-external-manual-1',
            amount: $expectedAmount,
            currency: 'CZK',
            variableSymbol: $variableSymbol,
            senderAccountNumber: '115-1643180257/0100',
            senderName: 'Jan Testovací',
            date: $now,
            comment: null,
        )));

        $this->entityManager->clear();

        $bankTx = $this->entityManager->getRepository(BankTransaction::class)
            ->findOneBy(['fioTransactionId' => 'fio-test-external-manual-1']);
        self::assertInstanceOf(BankTransaction::class, $bankTx);
        self::assertTrue($bankTx->isMatched(), 'external prepaid manual-track transfer must auto-match');
        self::assertNotNull($bankTx->pairedContract);
        self::assertTrue($bankTx->pairedContract->id->equals($contract->id));

        $reloadedRequest = $this->entityManager->find(ManualPaymentRequest::class, $request->id);
        self::assertNotNull($reloadedRequest);
        self::assertTrue($reloadedRequest->isPaid(), 'the pending payment request must be settled');

        $reloadedContract = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloadedContract);
        self::assertNotNull($reloadedContract->lastBilledAt, 'contract billing must be recorded (prolonged)');
        self::assertNotEquals(
            $billingPeriodStart->format('Y-m-d'),
            $reloadedContract->nextBillingDate?->format('Y-m-d'),
            'billing anchor must advance past the paid cycle',
        );
    }

    private function createExternalPrepaidManualContract(): Contract
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var Place $place */
        $place = $this->entityManager->getRepository(Place::class)->findOneBy(['name' => 'Sklad Praha - Centrum']);
        /** @var StorageType $storageType */
        $storageType = $this->entityManager->getRepository(StorageType::class)->findOneBy(['name' => 'Maly box', 'place' => $place]);

        $now = $this->clock->now();
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+6 months');

        $order = $this->orderService->createOrder(
            $tenant,
            $storageType,
            $place,
            $startDate,
            $endDate,
            $now,
            PaymentFrequency::MONTHLY,
        );

        // Mirror AdminOnboardingHandler for an externally-prepaid onboarding:
        // the first period is paid outside the system (paidThroughDate set), so
        // the payment method is EXTERNAL while later cycles bill on the MANUAL
        // bank-transfer track. A VS is assigned so incoming transfers can match.
        $order->setPaymentMethod(PaymentMethod::EXTERNAL);
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->setOnboardingBillingTerms(
            individualMonthlyAmount: null,
            paidThroughDate: $now->modify('+2 months'),
        );
        $order->assignVariableSymbol('9812340077');
        $order->markPaid($now);

        $contract = $this->orderService->completeOrder($order, $now);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $refetched = $this->entityManager->find(Contract::class, $contract->id);
        \assert($refetched instanceof Contract);

        return $refetched;
    }
}
