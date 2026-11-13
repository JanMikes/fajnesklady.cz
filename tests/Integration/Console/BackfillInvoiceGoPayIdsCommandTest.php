<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\DataFixtures\UserFixtures;
use App\Entity\BankTransaction;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Enum\RefundMode;
use App\Enum\StorageStatus;
use App\Tests\Mock\MockFakturoidClient;
use App\Tests\Mock\MockGoPayClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * app:backfill-invoice-gopay-ids (spec 093). Fixtures contain invoices of
 * their own, so assertions target the rows built here rather than the exit
 * code or the whole table.
 */
final class BackfillInvoiceGoPayIdsCommandTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private CommandTester $commandTester;
    private MockFakturoidClient $fakturoid;
    private MockGoPayClient $goPay;
    private \DateTimeImmutable $paidAt;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->fakturoid = $container->get(MockFakturoidClient::class);
        $this->fakturoid->reset();
        $this->goPay = $container->get(MockGoPayClient::class);
        $this->goPay->reset();
        $this->paidAt = new \DateTimeImmutable('2025-06-10 09:00:56');

        $application = new Application(static::$kernel);
        $this->commandTester = new CommandTester($application->find('app:backfill-invoice-gopay-ids'));
    }

    public function testStampsTheVerifiedGoPayIdOnACardPaidInvoiceOnce(): void
    {
        $order = $this->createCardPaidOrder('3200000001');
        $invoice = $this->createInvoice($order, 150_000, 6001);
        $this->entityManager->flush();
        $this->goPay->seedPaymentStatus('3200000001', 'PAID', 150_000);
        $csvPath = sys_get_temp_dir().'/gopay_backfill_'.Uuid::v7()->toBase58().'.csv';

        try {
            $this->commandTester->execute(['--csv' => $csvPath]);

            $this->assertSame('3200000001', $this->fakturoid->goPayPaymentIdOf(6001));
            $this->entityManager->refresh($invoice);
            $this->assertSame('3200000001', $invoice->goPayPaymentId);
            $this->assertStringContainsString('BACKFILL-6001;2025-06-10;1500,00;3200000001;updated', (string) file_get_contents($csvPath));
        } finally {
            @unlink($csvPath);
        }

        // Idempotent: the stamped invoice is no longer visited.
        $this->commandTester->execute([]);
        $this->assertStringNotContainsString('BACKFILL-6001', $this->commandTester->getDisplay());
    }

    public function testDryRunVerifiesButWritesNothing(): void
    {
        $order = $this->createCardPaidOrder('3200000002');
        $invoice = $this->createInvoice($order, 150_000, 6002);
        $this->entityManager->flush();
        $this->goPay->seedPaymentStatus('3200000002', 'PAID', 150_000);

        $this->commandTester->execute(['--dry-run' => true]);

        $this->assertMatchesRegularExpression('/BACKFILL-6002.+3200000002.+dry-run/', $this->commandTester->getDisplay());
        $this->assertNull($this->fakturoid->goPayPaymentIdOf(6002));
        $this->entityManager->refresh($invoice);
        $this->assertNull($invoice->goPayPaymentId);
    }

    /**
     * Invoices pair to payments by amount and time only — an id GoPay does not
     * confirm (other amount, never paid, a legacy bank-transaction UUID) must
     * never reach a tax document.
     */
    public function testIdGoPayDoesNotConfirmIsReportedNotWritten(): void
    {
        $amountMismatch = $this->createCardPaidOrder('3200000003');
        $this->createInvoice($amountMismatch, 150_000, 6003);
        $neverPaid = $this->createCardPaidOrder('0196a4b2-legacy-uuid');
        $this->createInvoice($neverPaid, 150_000, 6004);
        $this->entityManager->flush();
        $this->goPay->seedPaymentStatus('3200000003', 'PAID', 99_900);

        $this->commandTester->execute([]);

        $display = $this->commandTester->getDisplay();
        $this->assertMatchesRegularExpression('/BACKFILL-6003.+NOT VERIFIED/', $display);
        $this->assertMatchesRegularExpression('/BACKFILL-6004.+NOT VERIFIED/', $display);
        $this->assertNull($this->fakturoid->goPayPaymentIdOf(6003));
        $this->assertNull($this->fakturoid->goPayPaymentIdOf(6004));
        $this->assertSame(1, $this->commandTester->getStatusCode());
    }

    /**
     * Two same-amount invoices of one order resolve to the same payment. GoPay
     * would confirm the id for both, yet only one of them can be its invoice —
     * neither is provably right, so neither is written.
     */
    public function testIdMatchingTwoInvoicesIsWrittenToNeither(): void
    {
        $order = $this->createCardPaidOrder('3200000010');
        $this->createInvoice($order, 150_000, 6010);
        $this->createInvoice($order, 150_000, 6011);
        $this->entityManager->flush();
        $this->goPay->seedPaymentStatus('3200000010', 'PAID', 150_000);

        $this->commandTester->execute([]);

        $display = $this->commandTester->getDisplay();
        $this->assertMatchesRegularExpression('/BACKFILL-6010.+AMBIGUOUS/', $display);
        $this->assertMatchesRegularExpression('/BACKFILL-6011.+AMBIGUOUS/', $display);
        $this->assertNull($this->fakturoid->goPayPaymentIdOf(6010));
        $this->assertNull($this->fakturoid->goPayPaymentIdOf(6011));
    }

    public function testFakturoidRefusalLeavesTheInvoiceForTheNextRun(): void
    {
        $order = $this->createCardPaidOrder('3200000005');
        $invoice = $this->createInvoice($order, 150_000, 6005);
        $this->entityManager->flush();
        $this->goPay->seedPaymentStatus('3200000005', 'PAID', 150_000);
        $this->fakturoid->willRefuseUpdateOf(6005);

        $this->commandTester->execute([]);

        $this->assertMatchesRegularExpression('/BACKFILL-6005.+FAILED/', $this->commandTester->getDisplay());
        $this->entityManager->refresh($invoice);
        $this->assertNull($invoice->goPayPaymentId);
    }

    /**
     * Order 019f83e5 shape: a GoPay session was opened, the money came by
     * transfer. The invoice stays without an id and is not even listed.
     */
    public function testTransferPaidInvoiceIsLeftAlone(): void
    {
        $order = $this->createOrder();
        $order->setGoPayPaymentId('3200000006');
        $bankTransaction = $this->createBankTransaction();
        $payment = $this->createFirstPayment($order);
        $payment->linkBankTransaction($bankTransaction);
        $invoice = $this->createInvoice($order, 150_000, 6006);
        $this->entityManager->flush();
        $this->goPay->seedPaymentStatus('3200000006', 'PAID', 150_000);

        $this->commandTester->execute([]);

        $this->assertStringNotContainsString('BACKFILL-6006', $this->commandTester->getDisplay());
        $this->assertNull($this->fakturoid->goPayPaymentIdOf(6006));
        $this->entityManager->refresh($invoice);
        $this->assertNull($invoice->goPayPaymentId);
    }

    public function testDebtInvoiceGetsTheIdThatSettledTheDebt(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingDebt(230_000);
        $order->setDebtGoPayPaymentId('3200000007');
        $order->markDebtPaid($this->paidAt, PaymentMethod::GOPAY);
        $order->popEvents();
        $this->createInvoice($order, 230_000, 6007);
        $this->entityManager->flush();
        $this->goPay->seedPaymentStatus('3200000007', 'PAID', 230_000);

        $this->commandTester->execute([]);

        $this->assertSame('3200000007', $this->fakturoid->goPayPaymentIdOf(6007));
    }

    /**
     * A credit note carries the refunded payment's id — GoPay books the
     * refund under it — and is verified by a refunded state, not an amount.
     */
    public function testCreditNoteGetsTheRefundedPaymentId(): void
    {
        $order = $this->createOrder();
        $payment = $this->createFirstPayment($order);
        $payment->setGoPayPaymentId('3200000008');
        $original = $this->createInvoice($order, 150_000, 6008);
        $creditNote = $this->createInvoice($order, -50_000, 6009, correctionOf: $original);
        $refund = new PaymentRefund(
            id: Uuid::v7(),
            payment: $payment,
            order: $order,
            contract: null,
            amount: 50_000,
            mode: RefundMode::FULL,
            refundFrom: null,
            periodStart: null,
            periodEnd: null,
            issueCreditNote: true,
            reason: null,
            requestedBy: null,
            requestedAt: $this->paidAt,
        );
        $refund->markSucceeded($this->paidAt);
        $refund->attachCreditNote($creditNote);
        $this->entityManager->persist($refund);
        $this->entityManager->flush();
        $this->goPay->seedPaymentStatus('3200000008', 'PARTIALLY_REFUNDED', 150_000);

        $this->commandTester->execute([]);

        $this->assertSame('3200000008', $this->fakturoid->goPayPaymentIdOf(6008));
        $this->assertSame('3200000008', $this->fakturoid->goPayPaymentIdOf(6009));
    }

    private function createCardPaidOrder(string $goPayPaymentId): Order
    {
        $order = $this->createOrder();
        $order->setGoPayPaymentId($goPayPaymentId);
        $this->createFirstPayment($order)->setGoPayPaymentId($goPayPaymentId);

        return $order;
    }

    private function createOrder(): Order
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
        $order->setPaymentMethod(PaymentMethod::GOPAY);
        $order->markPaid($this->paidAt);
        $order->popEvents();
        $this->entityManager->persist($order);

        return $order;
    }

    private function createFirstPayment(Order $order): Payment
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

    private function createBankTransaction(): BankTransaction
    {
        $bankTransaction = new BankTransaction(
            id: Uuid::v7(),
            fioTransactionId: 'fio-'.Uuid::v7()->toBase58(),
            amount: 150_000,
            currency: 'CZK',
            variableSymbol: null,
            senderAccountNumber: null,
            senderName: null,
            transactionDate: $this->paidAt,
            comment: null,
            createdAt: $this->paidAt,
        );
        $this->entityManager->persist($bankTransaction);

        return $bankTransaction;
    }

    private function createInvoice(Order $order, int $amount, int $fakturoidInvoiceId, ?Invoice $correctionOf = null): Invoice
    {
        $invoice = new Invoice(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            fakturoidInvoiceId: $fakturoidInvoiceId,
            invoiceNumber: 'BACKFILL-'.$fakturoidInvoiceId,
            amount: $amount,
            issuedAt: $this->paidAt->modify('+1 second'),
            createdAt: $this->paidAt,
            correctionOf: $correctionOf,
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
