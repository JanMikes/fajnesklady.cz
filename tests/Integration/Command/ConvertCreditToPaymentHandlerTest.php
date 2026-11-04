<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Command\ConvertCreditToPaymentCommand;
use App\Entity\BankTransaction;
use App\Entity\Contract;
use App\Entity\Payment;
use App\Enum\PaymentMethod;
use App\Repository\PaymentRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use App\Tests\Mock\MockFakturoidClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Order 01a051d7: a 1 900 Kč transfer paid a cycle requested at 1 837 Kč and
 * 63 Kč was stranded as credit on a contract that then ended.
 */
final class ConvertCreditToPaymentHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private MessageBusInterface $commandBus;
    private MockFakturoidClient $fakturoid;
    private \DateTimeImmutable $paidAt;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->commandBus = $container->get('test.command.bus');
        $this->fakturoid = $container->get(MockFakturoidClient::class);
        $this->fakturoid->reset();
        $this->paidAt = new \DateTimeImmutable('2025-06-01 00:45:32');
    }

    public function testCreditBecomesAnInvoicedBankPaymentForTheCycleTheTransferPaid(): void
    {
        $contract = $this->findContract();
        $transfer = $this->createTransferPairedTo($contract, 190_000);
        $this->createCyclePayment($contract, $transfer, 183_700);
        $contract->addCredit(6_300);
        $this->entityManager->flush();

        $this->commandBus->dispatch(new ConvertCreditToPaymentCommand($contract, $transfer));

        $this->entityManager->refresh($contract);
        $this->assertSame(0, $contract->creditBalance);

        $payments = static::getContainer()->get(PaymentRepository::class)->findByBankTransaction($transfer);
        $this->assertCount(2, $payments);
        $topUp = array_values(array_filter($payments, static fn (Payment $p): bool => 6_300 === $p->amount));
        $this->assertCount(1, $topUp);
        $this->assertSame(PaymentMethod::BANK_TRANSFER, $topUp[0]->paidVia);
        $this->assertSame('2025-06-01', $topUp[0]->periodStart?->format('Y-m-d'));

        $invoices = $this->fakturoid->getCreatedInvoices();
        $this->assertCount(1, $invoices);
        $invoice = array_values($invoices)[0];
        $this->assertSame(6_300, $invoice->total);
        $this->assertSame(PaymentMethod::BANK_TRANSFER, $this->fakturoid->paymentMethodOf($invoice->id));
    }

    public function testRefusesATransferThatIsNotThisContractsMoney(): void
    {
        $contract = $this->findContract();
        $foreignTransfer = new BankTransaction(
            id: Uuid::v7(),
            fioTransactionId: 'fio-foreign-'.Uuid::v7()->toBase58(),
            amount: 6_300,
            currency: 'CZK',
            variableSymbol: null,
            senderAccountNumber: null,
            senderName: null,
            transactionDate: $this->paidAt,
            comment: null,
            createdAt: $this->paidAt,
        );
        $this->entityManager->persist($foreignTransfer);
        $contract->addCredit(6_300);
        $this->entityManager->flush();

        try {
            $this->commandBus->dispatch(new ConvertCreditToPaymentCommand($contract, $foreignTransfer));
            $this->fail('Expected the conversion to be refused.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf(\DomainException::class, HandlerFailureUnwrap::unwrap($e));
        }

        $this->assertSame([], $this->fakturoid->getCreatedInvoices());
    }

    private function findContract(): Contract
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.creditBalance = 0')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($contract instanceof Contract);

        return $contract;
    }

    private function createTransferPairedTo(Contract $contract, int $amount): BankTransaction
    {
        $transfer = new BankTransaction(
            id: Uuid::v7(),
            fioTransactionId: 'fio-credit-'.Uuid::v7()->toBase58(),
            amount: $amount,
            currency: 'CZK',
            variableSymbol: $contract->order->variableSymbol,
            senderAccountNumber: null,
            senderName: null,
            transactionDate: $this->paidAt,
            comment: null,
            createdAt: $this->paidAt,
        );
        $transfer->pairToContract($contract, 'variable_symbol', null, $this->paidAt);
        $this->entityManager->persist($transfer);

        return $transfer;
    }

    private function createCyclePayment(Contract $contract, BankTransaction $transfer, int $amount): void
    {
        $payment = new Payment(
            id: Uuid::v7(),
            order: null,
            contract: $contract,
            storage: $contract->storage,
            amount: $amount,
            paidAt: $this->paidAt,
            createdAt: $this->paidAt,
        );
        $payment->linkBankTransaction($transfer);
        $payment->coverPeriod(new \DateTimeImmutable('2025-06-01'), new \DateTimeImmutable('2025-06-30'));
        $this->entityManager->persist($payment);
    }
}
