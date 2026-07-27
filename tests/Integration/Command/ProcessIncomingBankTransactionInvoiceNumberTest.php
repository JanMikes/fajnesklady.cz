<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ProcessIncomingBankTransactionCommand;
use App\DataFixtures\UserFixtures;
use App\Entity\BankTransaction;
use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
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
 * Regression for the 2026-07-27 František Rajčan incident: a customer paid a
 * second time from the invoice PDF we e-mail after every settled charge, using
 * the invoice number as the variable symbol (Fakturoid's default VS when we
 * don't send our own). The invoice number is not an order VS, so the matcher
 * left 1 820 Kč unmatched even though the invoice identified the order
 * perfectly. The matcher now falls back to resolving the VS against invoice
 * numbers — leading-zero-insensitively, because banks strip the zeros of our
 * zero-padded numbers ("0012202607" arrives as "12202607").
 */
final class ProcessIncomingBankTransactionInvoiceNumberTest extends KernelTestCase
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

    public function testStrippedInvoiceNumberSymbolPairsToTheInvoiceOrder(): void
    {
        // The live incident: invoice number 0012202607, bank delivered VS 12202607.
        $contract = $this->createManualBillingContract('9812340077');
        $this->createInvoiceForOrder($contract->order, '0012202607');

        $cycleAmount = $this->amountCalculator->calculate($contract, $this->clock->now());

        $this->dispatchTransfer('fio-inv-num-1', $cycleAmount, '12202607');

        $bankTx = $this->findTransaction('fio-inv-num-1');
        self::assertTrue($bankTx->isMatched(), 'invoice-number VS must auto-match');
        self::assertSame('invoice_number', $bankTx->matchMethod);
        self::assertNotNull($bankTx->pairedContract);
        self::assertTrue($bankTx->pairedContract->id->equals($contract->id));

        // The money settled the next manual-billing cycle, so the anchor advanced.
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        self::assertNotNull($reloaded);
        self::assertNotNull($reloaded->lastBilledAt, 'cycle must be recorded as billed');

        // The recorded Payment references the bank transaction — NOT goPayPaymentId,
        // which would render the payment as "Kartou (GoPay)" in the overview.
        $payment = $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(\App\Entity\Payment::class, 'p')
            ->where('p.contract = :contract')
            ->setParameter('contract', $reloaded)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        self::assertInstanceOf(\App\Entity\Payment::class, $payment);
        self::assertNull($payment->goPayPaymentId);
        self::assertNotNull($payment->bankTransaction);
        self::assertTrue($payment->bankTransaction->id->equals($bankTx->id));
    }

    public function testFullInvoiceNumberSymbolPairsToo(): void
    {
        $contract = $this->createManualBillingContract('9812340078');
        $this->createInvoiceForOrder($contract->order, '0012202608');

        $cycleAmount = $this->amountCalculator->calculate($contract, $this->clock->now());

        $this->dispatchTransfer('fio-inv-num-2', $cycleAmount, '0012202608');

        $bankTx = $this->findTransaction('fio-inv-num-2');
        self::assertTrue($bankTx->isMatched());
        self::assertSame('invoice_number', $bankTx->matchMethod);
    }

    public function testOrderVariableSymbolStillWinsOverInvoiceNumber(): void
    {
        // An order whose VS happens to equal another order's invoice number must
        // receive the money — the direct VS match is the stronger signal.
        $contract = $this->createManualBillingContract('9812340079');
        $this->createInvoiceForOrder($contract->order, '0012202609');

        $decoyOrder = $this->createSignedBankTransferOrder('12202609', 'D1');

        $this->dispatchTransfer('fio-inv-num-3', $decoyOrder->firstPaymentPrice, '12202609');

        $bankTx = $this->findTransaction('fio-inv-num-3');
        self::assertNotNull($bankTx->pairedOrder);
        self::assertTrue(
            $bankTx->pairedOrder->id->equals($decoyOrder->id),
            'direct order-VS match must take precedence over the invoice-number fallback',
        );
        self::assertSame('variable_symbol', $bankTx->matchMethod);
    }

    public function testAmbiguousInvoiceNumberStaysUnmatched(): void
    {
        // Two invoices whose numbers strip to the same digits but belong to
        // different orders — the matcher must not guess.
        $orderA = $this->createSignedBankTransferOrder('9912340080', 'D1');
        $orderB = $this->createSignedBankTransferOrder('9912340081', 'D2');
        $this->createInvoiceForOrder($orderA, '0071502601');
        $this->createInvoiceForOrder($orderB, '071502601');

        $this->dispatchTransfer('fio-inv-num-4', 150_000, '71502601');

        $bankTx = $this->findTransaction('fio-inv-num-4');
        self::assertNull($bankTx->pairedOrder, 'ambiguous invoice-number VS must stay for a human');
        self::assertNull($bankTx->pairedContract);
    }

    private function dispatchTransfer(string $fioId, int $amount, string $variableSymbol): void
    {
        $this->commandBus->dispatch(new ProcessIncomingBankTransactionCommand(new FioBankTransaction(
            id: $fioId,
            amount: $amount,
            currency: 'CZK',
            variableSymbol: $variableSymbol,
            senderAccountNumber: '1716657010/3030',
            senderName: 'Jan Testovací',
            date: $this->clock->now(),
            comment: null,
        )));

        $this->entityManager->clear();
    }

    private function findTransaction(string $fioId): BankTransaction
    {
        $tx = $this->entityManager->createQueryBuilder()
            ->select('bt')
            ->from(BankTransaction::class, 'bt')
            ->where('bt.fioTransactionId = :fioId')
            ->setParameter('fioId', $fioId)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($tx instanceof BankTransaction, 'Bank transaction was not ingested at all.');

        return $tx;
    }

    private function createInvoiceForOrder(Order $order, string $invoiceNumber): void
    {
        $now = $this->clock->now();

        $this->entityManager->persist(new Invoice(
            id: $this->identity->next(),
            order: $order,
            user: $order->user,
            fakturoidInvoiceId: (int) ltrim($invoiceNumber, '0'),
            invoiceNumber: $invoiceNumber,
            amount: 182_000,
            issuedAt: $now,
            createdAt: $now,
        ));

        $this->entityManager->flush();
    }

    /**
     * Mirror of the external-prepaid onboarding shape from
     * ProcessIncomingBankTransactionExternalManualBillingTest — a completed
     * contract billing later cycles on the manual bank-transfer track.
     */
    private function createManualBillingContract(string $variableSymbol): Contract
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
            $now->modify('+6 months'),
            $now,
            PaymentFrequency::MONTHLY,
        );

        $order->setPaymentMethod(PaymentMethod::EXTERNAL);
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->setOnboardingBillingTerms(
            individualMonthlyAmount: null,
            paidThroughDate: $now->modify('+2 months'),
        );
        $order->assignVariableSymbol($variableSymbol);
        $order->markPaid($now);

        $contract = $this->orderService->completeOrder($order, $now);

        $this->entityManager->flush();
        $this->entityManager->clear();

        $refetched = $this->entityManager->find(Contract::class, $contract->id);
        \assert($refetched instanceof Contract);

        return $refetched;
    }

    /**
     * Built directly instead of via OrderService::createOrder — the matcher does
     * not care how the order was born, and the fixture storage pools have no
     * spare capacity in the test window for a second service-created booking.
     */
    private function createSignedBankTransferOrder(string $variableSymbol, string $storageNumber): Order
    {
        /** @var User $tenant */
        $tenant = $this->entityManager->getRepository(User::class)->findOneBy(['email' => UserFixtures::TENANT_EMAIL]);
        /** @var Storage $storage */
        $storage = $this->entityManager->getRepository(Storage::class)->findOneBy(['number' => $storageNumber]);

        $now = $this->clock->now();

        $order = new Order(
            id: $this->identity->next(),
            user: $tenant,
            storage: $storage,
            paymentFrequency: null,
            startDate: $now->modify('+1 day'),
            endDate: $now->modify('+93 days'),
            firstPaymentPrice: 150_000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );

        $order->acceptTerms($now);
        $order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        $order->setBillingMode(BillingMode::ONE_TIME);
        $order->assignVariableSymbol($variableSymbol);

        $this->entityManager->persist($order);
        $this->entityManager->flush();

        return $order;
    }
}
