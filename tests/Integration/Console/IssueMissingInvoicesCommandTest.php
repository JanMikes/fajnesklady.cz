<?php

declare(strict_types=1);

namespace App\Tests\Integration\Console;

use App\DataFixtures\InvoiceFixtures;
use App\DataFixtures\OrderFixtures;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Enum\PaymentMethod;
use App\Repository\InvoiceRepository;
use App\Tests\Mock\MockFakturoidClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class IssueMissingInvoicesCommandTest extends KernelTestCase
{
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

        /** @var MockFakturoidClient $fakturoid */
        $fakturoid = $container->get(MockFakturoidClient::class);
        $fakturoid->reset();

        $this->application = new Application(self::$kernel);
    }

    public function testIssuesInvoiceForPaidOrderMissingOneAfterGracePeriod(): void
    {
        // REF_ORDER_COMPLETED was paid 6 days ago (well past the 15-min grace).
        // Drop its invoice to simulate a Fakturoid outage during the synchronous
        // bundle path — the cron must issue a fresh invoice for it.
        $order = $this->getOrder(OrderFixtures::REF_ORDER_COMPLETED);
        $existingInvoice = $this->getInvoice(InvoiceFixtures::REF_INVOICE_COMPLETED);
        $this->entityManager->remove($existingInvoice);
        $this->entityManager->flush();

        $tester = $this->runCommand();

        $tester->assertCommandIsSuccessful();
        $this->entityManager->clear();

        $newInvoice = $this->invoiceRepository->findByOrder($this->getOrder(OrderFixtures::REF_ORDER_COMPLETED));
        $this->assertNotNull($newInvoice, 'Cron must issue an invoice for our target order.');
        $this->assertSame($order->id->toRfc4122(), $newInvoice->order->id->toRfc4122());
    }

    public function testSkipsPaidOrderInsideGraceWindow(): void
    {
        // Drop the invoice but pull paidAt forward to inside the grace window
        // (5 minutes ago) — the cron must back off for THIS order so it doesn't
        // race the synchronous SendRentalActivatedEmailHandler path. Other
        // fixture orders outside the grace window can still be processed; we
        // only assert on the target order.
        $order = $this->getOrder(OrderFixtures::REF_ORDER_COMPLETED);
        $existingInvoice = $this->getInvoice(InvoiceFixtures::REF_INVOICE_COMPLETED);
        $this->entityManager->remove($existingInvoice);
        $this->entityManager->flush();

        $this->setPaidAtViaSql($order, new \DateTimeImmutable('2025-06-15 11:55:00'));

        $tester = $this->runCommand();

        $tester->assertCommandIsSuccessful();
        $this->entityManager->clear();

        $refreshed = $this->getOrder(OrderFixtures::REF_ORDER_COMPLETED);
        $this->assertNull(
            $this->invoiceRepository->findByOrder($refreshed),
            'Target order is inside the grace window — no invoice should be issued yet.',
        );
    }

    public function testSkipsExternalPaymentOrder(): void
    {
        // Admin-onboarded / migrated orders have paymentMethod = EXTERNAL —
        // they were marked "paid" administratively, no money ran through the
        // system. The backstop must skip them; an invoice would be wrong.
        $order = $this->getOrder(OrderFixtures::REF_ORDER_COMPLETED);
        $existingInvoice = $this->getInvoice(InvoiceFixtures::REF_INVOICE_COMPLETED);
        $this->entityManager->remove($existingInvoice);
        $order->setPaymentMethod(PaymentMethod::EXTERNAL);
        $this->entityManager->flush();

        $this->runCommand()->assertCommandIsSuccessful();
        $this->entityManager->clear();

        $refreshed = $this->getOrder(OrderFixtures::REF_ORDER_COMPLETED);
        $this->assertNull(
            $this->invoiceRepository->findByOrder($refreshed),
            'EXTERNAL-payment orders must not be invoiced by the cron backstop.',
        );
    }

    public function testDoesNotReissueWhenInvoiceAlreadyExists(): void
    {
        // Default fixtures: REF_ORDER_COMPLETED already has REF_INVOICE_COMPLETED.
        // Whatever the cron does with other orders, it must NOT issue a second
        // invoice on this one — the backstop is a backstop, not a re-issuer.
        $order = $this->getOrder(OrderFixtures::REF_ORDER_COMPLETED);
        $originalInvoiceId = $this->invoiceRepository->findByOrder($order)?->id;
        $this->assertNotNull($originalInvoiceId, 'Sanity: fixture order should start with an invoice.');

        $this->runCommand()->assertCommandIsSuccessful();
        $this->entityManager->clear();

        $refreshed = $this->getOrder(OrderFixtures::REF_ORDER_COMPLETED);
        $invoicesForOrder = $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(Invoice::class, 'i')
            ->where('i.order = :order')
            ->setParameter('order', $refreshed)
            ->getQuery()
            ->getSingleScalarResult();

        $this->assertSame(1, (int) $invoicesForOrder, 'Cron must not create a duplicate invoice when one already exists.');
    }

    private function runCommand(): CommandTester
    {
        $tester = new CommandTester($this->application->find('app:issue-missing-invoices'));
        $tester->execute([]);

        return $tester;
    }

    private function getOrder(string $reference): Order
    {
        // Fixture-reference loading is not available from KernelTestCase, so
        // look orders up by the storage number that uniquely identifies each
        // fixture row (B3 = REF_ORDER_COMPLETED, C1 = REF_ORDER_COMPLETED_RECURRING).
        $storageNumberByRef = [
            OrderFixtures::REF_ORDER_COMPLETED => 'B3',
            OrderFixtures::REF_ORDER_COMPLETED_RECURRING => 'C1',
        ];
        $storageNumber = $storageNumberByRef[$reference] ?? throw new \InvalidArgumentException('Unmapped reference');

        $found = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.number = :number')
            ->setParameter('number', $storageNumber)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($found instanceof Order);

        return $found;
    }

    private function getInvoice(string $reference): Invoice
    {
        $invoiceNumberByRef = [
            InvoiceFixtures::REF_INVOICE_COMPLETED => '2025-0001',
        ];
        $number = $invoiceNumberByRef[$reference] ?? throw new \InvalidArgumentException('Unmapped reference');

        $invoice = $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->where('i.invoiceNumber = :number')
            ->setParameter('number', $number)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($invoice instanceof Invoice);

        return $invoice;
    }

    private function setPaidAtViaSql(Order $order, \DateTimeImmutable $paidAt): void
    {
        // Order.paidAt is private(set) and there's no public setter — go
        // around the entity for this isolated test fixture adjustment.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE orders SET paid_at = :paidAt WHERE id = :id',
            [
                'paidAt' => $paidAt->format('Y-m-d H:i:s'),
                'id' => $order->id->toRfc4122(),
            ],
        );
        $this->entityManager->clear();
    }
}
