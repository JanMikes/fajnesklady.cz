<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\DataFixtures\UserFixtures;
use App\Entity\BankTransaction;
use App\Entity\BankTransactionAllocation;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\AllocationStepType;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Enum\StorageStatus;
use App\Service\AuditLogger;
use App\Tests\Mock\MockFakturoidClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * The production shapes app:backfill-payment-methods exists for: rows written
 * before the payment source was stored. Fixtures also contain invoices, so the
 * assertions target the rows built here rather than the global exit code.
 */
final class BackfillPaymentMethodsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommandTester $commandTester;
    private MockFakturoidClient $fakturoid;
    private \DateTimeImmutable $paidAt;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->fakturoid = $container->get(MockFakturoidClient::class);
        $this->fakturoid->reset();
        $this->paidAt = new \DateTimeImmutable('2025-06-10 09:00:56');

        $application = new Application(static::$kernel);
        $this->commandTester = new CommandTester($application->find('app:backfill-payment-methods'));
    }

    /**
     * Order 019f83e5 in production: the customer chose the card (the order
     * carries a GoPay session id), then paid by transfer. The paired bank
     * transaction — not the GoPay id — decides.
     */
    public function testCardOrderSettledByTransferIsRecordedAndInvoicedAsTransfer(): void
    {
        $order = $this->createPaidOrder(PaymentMethod::GOPAY);
        $order->setGoPayPaymentId('gp-abandoned-session');
        $payment = $this->createLegacyFirstPayment($order);
        $bankTransaction = $this->createTransferPairedTo($order, 150_000);
        $invoice = $this->createInvoice($order, 150_000, $this->paidAt, 5001);
        $this->entityManager->flush();

        $this->commandTester->execute([]);

        $this->entityManager->refresh($payment);
        $this->entityManager->refresh($order);
        $this->assertNotNull($payment->bankTransaction);
        $this->assertTrue($payment->bankTransaction->id->equals($bankTransaction->id));
        $this->assertSame(PaymentMethod::BANK_TRANSFER, $order->paidVia);
        $this->assertSame(PaymentMethod::BANK_TRANSFER, $this->fakturoid->paymentMethodOf($invoice->fakturoidInvoiceId));
    }

    /**
     * Order 01a010a6: the admin marked the onboarding debt as settled off-system.
     */
    public function testDebtSettledByAdminIsRecordedAsOffSystem(): void
    {
        $order = $this->createPaidOrder(PaymentMethod::BANK_TRANSFER);
        $this->createLegacyFirstPayment($order);
        $this->createTransferPairedTo($order, 150_000);
        $order->setOnboardingDebt(230_000);
        $order->markDebtPaid($this->paidAt, PaymentMethod::EXTERNAL);
        $this->clearDebtPaidVia($order);
        static::getContainer()->get(AuditLogger::class)->log(
            entityType: 'order',
            entityId: $order->id->toRfc4122(),
            eventType: 'onboarding_debt_settled',
            payload: ['amount' => 230_000, 'source' => 'admin_external'],
            orderId: $order->id,
        );
        $debtInvoice = $this->createInvoice($order, 230_000, $this->paidAt, 5002);
        $this->entityManager->flush();

        $this->commandTester->execute([]);

        $this->entityManager->refresh($order);
        $this->assertSame(PaymentMethod::EXTERNAL, $order->debtPaidVia);
        // Off-system money is never invoiced: the stray invoice is reported, not relabelled.
        $this->assertNull($this->fakturoid->paymentMethodOf($debtInvoice->fakturoidInvoiceId));
        $this->assertStringContainsString('should not have an invoice', $this->commandTester->getDisplay());
    }

    /**
     * Order 019f5d05 shape: the debt was wired, the first payment was a card
     * charge. The debt transfer is also paired to the order (before the
     * contract exists) and must not be mistaken for the first payment.
     */
    public function testDebtTransferIsNotLinkedToTheFirstPayment(): void
    {
        $order = $this->createPaidOrder(PaymentMethod::GOPAY);
        $payment = $this->createLegacyFirstPayment($order);
        $debtTransfer = $this->createTransferPairedTo($order, 150_000);
        $this->entityManager->persist(new BankTransactionAllocation(
            id: Uuid::v7(),
            bankTransaction: $debtTransfer,
            type: AllocationStepType::ONBOARDING_DEBT,
            amountInHaler: 150_000,
            order: $order,
            createdAt: $this->paidAt,
        ));
        $invoice = $this->createInvoice($order, 150_000, $this->paidAt, 5005);
        $this->entityManager->flush();

        $this->commandTester->execute([]);

        $this->entityManager->refresh($payment);
        $this->entityManager->refresh($order);
        $this->assertNull($payment->bankTransaction);
        $this->assertNull($order->paidVia, 'unproven — left for manual review');
        $this->assertNull($this->fakturoid->paymentMethodOf($invoice->fakturoidInvoiceId));
        $this->assertStringContainsString('check manually', $this->commandTester->getDisplay());
    }

    /**
     * A legacy row without links on a transfer order is not proof of an
     * off-system payment — it just predates the links.
     */
    public function testUnlinkedLegacyPaymentIsNotStampedOffSystem(): void
    {
        $order = $this->createPaidOrder(PaymentMethod::BANK_TRANSFER);
        $this->createLegacyFirstPayment($order);
        $invoice = $this->createInvoice($order, 150_000, $this->paidAt, 5006);
        $this->entityManager->flush();

        $this->commandTester->execute([]);

        $this->entityManager->refresh($order);
        $this->assertNull($order->paidVia);
        $this->assertNull($this->fakturoid->paymentMethodOf($invoice->fakturoidInvoiceId));
        $this->assertStringContainsString('BACKFILL-5006', $this->commandTester->getDisplay());
    }

    public function testDryRunWritesNothing(): void
    {
        $order = $this->createPaidOrder(PaymentMethod::BANK_TRANSFER);
        $payment = $this->createLegacyFirstPayment($order);
        $this->createTransferPairedTo($order, 150_000);
        $invoice = $this->createInvoice($order, 150_000, $this->paidAt, 5003);
        $this->entityManager->flush();

        $this->commandTester->execute(['--dry-run' => true]);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Payment::class, $payment->id);
        $this->assertNotNull($reloaded);
        $this->assertNull($reloaded->bankTransaction);
        $this->assertNull($this->fakturoid->paymentMethodOf($invoice->fakturoidInvoiceId));
    }

    public function testLockedInvoiceIsReportedAndFailsTheRun(): void
    {
        $order = $this->createPaidOrder(PaymentMethod::BANK_TRANSFER);
        $this->createLegacyFirstPayment($order);
        $this->createTransferPairedTo($order, 150_000);
        $invoice = $this->createInvoice($order, 150_000, $this->paidAt, 5004);
        $this->entityManager->flush();
        $this->fakturoid->willRefuseUpdateOf($invoice->fakturoidInvoiceId);

        $exitCode = $this->commandTester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FAILED', $this->commandTester->getDisplay());
        $this->assertNull($this->fakturoid->paymentMethodOf($invoice->fakturoidInvoiceId));
    }

    /**
     * A paid order exactly as the system stored it before Order::$paidVia
     * existed: paid, but with no record of how.
     */
    private function createPaidOrder(PaymentMethod $chosenMethod): Order
    {
        $order = new Order(
            id: Uuid::v7(),
            user: $this->findTenant(),
            storage: $this->findAvailableStorage(),
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: new \DateTimeImmutable('2025-06-10'),
            endDate: new \DateTimeImmutable('2025-12-10'),
            firstPaymentPrice: 150_000,
            expiresAt: new \DateTimeImmutable('2025-07-10'),
            createdAt: new \DateTimeImmutable('2025-06-09'),
        );
        $order->setPaymentMethod($chosenMethod);
        $order->markPaid($this->paidAt);
        $order->popEvents();
        $this->entityManager->persist($order);
        $this->entityManager->flush();

        // markPaid() now records paidVia; the legacy rows have none.
        $this->entityManager->getConnection()->executeStatement('UPDATE orders SET paid_via = NULL WHERE id = ?', [$order->id->toRfc4122()]);
        $this->entityManager->refresh($order);

        return $order;
    }

    private function clearDebtPaidVia(Order $order): void
    {
        $order->popEvents();
        $this->entityManager->flush();
        $this->entityManager->getConnection()->executeStatement('UPDATE orders SET debt_paid_via = NULL WHERE id = ?', [$order->id->toRfc4122()]);
        $this->entityManager->refresh($order);
    }

    private function createLegacyFirstPayment(Order $order): Payment
    {
        $payment = new Payment(
            id: Uuid::v7(),
            order: $order,
            contract: null,
            storage: $order->storage,
            amount: 150_000,
            paidAt: $this->paidAt,
            createdAt: $this->paidAt,
        );
        $this->entityManager->persist($payment);

        return $payment;
    }

    private function createTransferPairedTo(Order $order, int $amount): BankTransaction
    {
        $bankTransaction = new BankTransaction(
            id: Uuid::v7(),
            fioTransactionId: 'fio-'.Uuid::v7()->toBase58(),
            amount: $amount,
            currency: 'CZK',
            variableSymbol: null,
            senderAccountNumber: null,
            senderName: null,
            transactionDate: $this->paidAt,
            comment: null,
            createdAt: $this->paidAt,
        );
        $bankTransaction->pairToOrder($order, 'variable_symbol', null, $this->paidAt);
        $this->entityManager->persist($bankTransaction);

        return $bankTransaction;
    }

    private function createInvoice(Order $order, int $amount, \DateTimeImmutable $issuedAt, int $fakturoidInvoiceId): Invoice
    {
        $invoice = new Invoice(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            fakturoidInvoiceId: $fakturoidInvoiceId,
            invoiceNumber: 'BACKFILL-'.$fakturoidInvoiceId,
            amount: $amount,
            issuedAt: $issuedAt->modify('+1 second'),
            createdAt: $issuedAt,
        );
        $invoice->popEvents(); // no InvoiceCreated e-mail for a historical row
        $this->entityManager->persist($invoice);

        return $invoice;
    }

    private function findTenant(): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', UserFixtures::TENANT_EMAIL)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($user instanceof User, 'Tenant fixture user not found');

        return $user;
    }

    private function findAvailableStorage(): Storage
    {
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Storage::class, 's')
            ->where('s.status = :status')
            ->setParameter('status', StorageStatus::AVAILABLE)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($storage instanceof Storage, 'No available storage found in fixtures');

        return $storage;
    }
}
