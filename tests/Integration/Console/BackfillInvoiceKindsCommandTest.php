<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Enum\InvoiceKind;
use App\Repository\InvoiceRepository;
use App\Tests\Mock\MockFakturoidClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Reconstructs order 019f5d05 (2026-11): an onboarding-debt invoice issued
 * before the first card payment made that payment look invoiced. Classifying
 * history must surface — and then let the backstop fix — exactly that gap.
 */
final class BackfillInvoiceKindsCommandTest extends KernelTestCase
{
    private const int DEBT = 616_000;

    private EntityManagerInterface $entityManager;
    private InvoiceRepository $invoiceRepository;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $this->entityManager = $doctrine->getManager();
        $this->invoiceRepository = $container->get(InvoiceRepository::class);
        $container->get(MockFakturoidClient::class)->reset();
        $this->application = new Application(self::$kernel);
    }

    public function testDryRunListsTheOrderTheBackstopWillInvoiceAndWritesNothing(): void
    {
        $order = $this->orderWithOnlyALegacyDebtInvoice();

        $tester = $this->runBackfill(['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();
        $this->assertStringContainsString('onboarding_debt', $display);
        $this->assertStringContainsString('WOULD', $display);
        $this->assertStringContainsString($order->id->toRfc4122(), $display);

        $this->entityManager->clear();
        $this->assertNull($this->legacyInvoice()->kind, 'A dry run must not write anything.');
    }

    public function testClassifiesTheDebtInvoiceSoTheBackstopIssuesTheFirstPaymentInvoice(): void
    {
        $order = $this->orderWithOnlyALegacyDebtInvoice();

        $tester = $this->runBackfill([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString($order->id->toRfc4122(), $tester->getDisplay());
        $this->entityManager->clear();
        $this->assertSame(InvoiceKind::ONBOARDING_DEBT, $this->legacyInvoice()->kind);

        // The 5-minute backstop then issues the missing first-payment invoice.
        (new CommandTester($this->application->find('app:issue-missing-invoices')))->execute([]);
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Order::class, $order->id);
        self::assertNotNull($reloaded);
        $firstPayment = $this->invoiceRepository->findFirstPaymentInvoice($reloaded);
        self::assertNotNull($firstPayment);
        self::assertSame(InvoiceKind::FIRST_PAYMENT, $firstPayment->kind);

        // Idempotent: nothing left to classify, nothing left to invoice.
        $again = $this->runBackfill([]);
        $again->assertCommandIsSuccessful();
        $this->assertStringContainsString('No order is left without a first-payment invoice.', $again->getDisplay());
    }

    public function testUnmatchableInvoiceStaysUnclassified(): void
    {
        $this->orderWithOnlyALegacyDebtInvoice();
        // Nothing on the order carries this amount → no match within 24 h.
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE invoice SET amount = 12345 WHERE invoice_number = '2025-0001'",
        );

        $tester = $this->runBackfill([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('could not be matched', $tester->getDisplay());
        $this->entityManager->clear();
        $this->assertNull($this->legacyInvoice()->kind);
    }

    /**
     * Fixture order B3 (REF_ORDER_COMPLETED) reshaped: its only invoice becomes
     * an unclassified onboarding-debt invoice issued when the debt was paid.
     */
    private function orderWithOnlyALegacyDebtInvoice(): Order
    {
        $invoice = $this->legacyInvoice();
        $order = $invoice->order;
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'UPDATE invoice SET kind = NULL, amount = :debt WHERE id = :id',
            ['debt' => self::DEBT, 'id' => $invoice->id->toRfc4122()],
        );
        $connection->executeStatement(
            "UPDATE orders SET onboarding_debt_in_haler = :debt, debt_paid_at = :at, debt_paid_via = 'bank_transfer' WHERE id = :id",
            ['debt' => self::DEBT, 'at' => $invoice->issuedAt->format('Y-m-d H:i:s'), 'id' => $order->id->toRfc4122()],
        );
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(Order::class, $order->id);
        \assert($reloaded instanceof Order);

        return $reloaded;
    }

    private function legacyInvoice(): Invoice
    {
        $invoice = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.invoiceNumber = :number')
            ->setParameter('number', '2025-0001')
            ->getQuery()
            ->getOneOrNullResult();
        \assert($invoice instanceof Invoice);

        return $invoice;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function runBackfill(array $options): CommandTester
    {
        $tester = new CommandTester($this->application->find('app:backfill-invoice-kinds'));
        $tester->execute($options);

        return $tester;
    }
}
