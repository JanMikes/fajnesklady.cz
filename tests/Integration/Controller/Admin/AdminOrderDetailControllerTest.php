<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\BankTransaction;
use App\Entity\Contract;
use App\Entity\Fine;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\FineType;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Repository\PaymentRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class AdminOrderDetailControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testDeferredOrderShowsAwaitingChoiceIndicator(): void
    {
        // Spec 088: a deferred onboarding renders without error and shows
        // "Zákazník zvolí" instead of a concrete (provisional) method/price.
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.status = :reserved')
            ->setParameter('reserved', OrderStatus::RESERVED)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($order instanceof Order);

        $order->markCustomerChoosesPayment();
        (new \ReflectionClass($order))->getProperty('paymentMethod')->setValue($order, null);
        $this->entityManager->flush();

        $this->client->loginUser($this->findAdmin(), 'main');
        $this->client->request('GET', '/portal/admin/orders/'.$order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Zákazník zvolí');
    }

    public function testRendersHistoriCenyPanelForOnboardedContract(): void
    {
        $this->client->loginUser($this->findAdmin(), 'main');

        $order = $this->findFirstOnboardedOrder();

        $this->client->request('GET', '/portal/admin/orders/'.$order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Historie ceny');
        $this->assertSelectorTextContains('body', 'Initial value (fixture)');
    }

    public function testNoHistoriCenyPanelForVanillaOrder(): void
    {
        $this->client->loginUser($this->findAdmin(), 'main');

        $order = $this->findVanillaContractOrder();

        $this->client->request('GET', '/portal/admin/orders/'.$order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('body', 'Historie ceny');
    }

    public function testRendersStageHeaderAndPaymentOverview(): void
    {
        $this->client->loginUser($this->findAdmin(), 'main');

        $contract = $this->findAnyContract();

        $this->client->request('GET', '/portal/admin/orders/'.$contract->order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        // Stage card facts + payments table are always present.
        $this->assertStringContainsString('Přehled plateb', $body);
        $this->assertStringContainsString('Zaplaceno v systému', $body);
        $this->assertStringContainsString('Způsob platby', $body);
        $this->assertStringContainsString($contract->user->fullName, $body);
    }

    public function testPaidFineWithInvoiceShowsInvoiceLinkInFinesTable(): void
    {
        $contract = $this->findAnyContract();
        $fine = $this->createPaidFine($contract);
        $invoice = $this->createFineInvoice($fine);

        $this->client->loginUser($this->findAdmin(), 'main');
        $this->client->request('GET', '/portal/admin/orders/'.$contract->order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Smluvní pokuty', $body);
        $this->assertStringContainsString('/portal/faktury/'.$invoice->id->toRfc4122().'/pdf?download=1', $body);
    }

    public function testPaidFineWithoutInvoiceShowsNoInvoiceLinkInFinesTable(): void
    {
        $contract = $this->findAnyContract();
        $this->createPaidFine($contract);

        $this->client->loginUser($this->findAdmin(), 'main');
        $this->client->request('GET', '/portal/admin/orders/'.$contract->order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Smluvní pokuty', $body);
        // The invoices panel links without ?download=1 — only the fines-table
        // invoice link uses it, so its absence proves the row has no link.
        $this->assertStringNotContainsString('/pdf?download=1', $body);
    }

    /**
     * Order 01a051d7: the customer wired 1 900 Kč for a cycle requested at
     * 1 837 Kč. "Přijato bankovním převodem" then disagreed with "Zaplaceno"
     * by the 63 Kč parked as credit, and the page told the admin to check the
     * pairing — although nothing was mispaired. Payment wording is one
     * vocabulary: method first, no "Ručně schvalovaná platba", no raw keys.
     */
    public function testOverpaymentParkedAsCreditIsExplainedNotFlaggedAsPairingError(): void
    {
        $contract = $this->findContractWithoutBankTransfers();
        $order = $contract->order;
        $order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);

        $totalPaid = static::getContainer()->get(PaymentRepository::class)->sumPaidForOrder($order, $contract);
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $transfer = new BankTransaction(
            id: Uuid::v7(),
            fioTransactionId: 'fio-overpay-'.Uuid::v7()->toBase58(),
            amount: $totalPaid + 6_300,
            currency: 'CZK',
            variableSymbol: $order->variableSymbol,
            senderAccountNumber: null,
            senderName: null,
            transactionDate: $now,
            comment: null,
            createdAt: $now,
        );
        $transfer->pairToContract($contract, 'variable_symbol', null, $now);
        $this->entityManager->persist($transfer);
        $contract->addCredit(6_300);
        static::getContainer()->get(AuditLogger::class)->log(
            entityType: 'bank_transaction',
            entityId: $transfer->id->toRfc4122(),
            eventType: 'allocated',
            payload: ['incoming_amount' => 190_000, 'steps' => [
                ['type' => 'billing_cycle', 'allocated' => 183_700],
                ['type' => 'credit', 'allocated' => 6_300],
            ]],
            orderId: $order->id,
        );
        $this->entityManager->flush();

        $this->client->loginUser($this->findAdmin(), 'main');
        $this->client->request('GET', '/portal/admin/orders/'.$order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Z toho přeplatek 63,00 Kč evidovaný jako kredit smlouvy', $body);
        $this->assertStringNotContainsString('zkontrolujte spárování plateb', $body);
        $this->assertStringContainsString('Bankovní převod — výzva e-mailem před každou platbou', $body);
        $this->assertStringContainsString('Bankovní převod rozúčtován', $body);
        $this->assertStringContainsString('nájem za období 1 837 Kč, přeplatek → kredit 63 Kč', $body);
        $this->assertStringNotContainsString('bank_transaction.allocated', $body);
        $this->assertStringNotContainsString('Ručně schvalovaná', $body);
    }

    private function findContractWithoutBankTransfers(): Contract
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.terminatedAt IS NULL')
            ->andWhere('NOT EXISTS (SELECT bt.id FROM '.BankTransaction::class.' bt WHERE bt.pairedOrder = c.order)')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($contract instanceof Contract, 'No fixture contract without bank transfers.');

        return $contract;
    }

    private function findAnyContract(): Contract
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($contract instanceof Contract, 'No fixture contract available.');

        return $contract;
    }

    private function createPaidFine(Contract $contract): Fine
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $fine = new Fine(
            id: Uuid::v7(),
            contract: $contract,
            user: $contract->user,
            issuedBy: $this->findAdmin(),
            type: FineType::DIRTY_STORAGE,
            amountInHaler: 600_000,
            description: 'Znečištěná skladovací jednotka.',
            issuedAt: $now->modify('-1 day'),
            createdAt: $now->modify('-1 day'),
        );
        $fine->markPaid($now, PaymentMethod::GOPAY);
        $fine->popEvents();
        $this->entityManager->persist($fine);
        $this->entityManager->flush();

        return $fine;
    }

    private function createFineInvoice(Fine $fine): Invoice
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $invoice = new Invoice(
            id: Uuid::v7(),
            order: $fine->contract->order,
            user: $fine->user,
            fakturoidInvoiceId: 987656,
            invoiceNumber: 'FV-2025-FINE-3',
            amount: $fine->amountInHaler,
            issuedAt: $now,
            createdAt: $now,
            fine: $fine,
        );
        $path = tempnam(sys_get_temp_dir(), 'fine_invoice_');
        \assert(is_string($path));
        file_put_contents($path, '%PDF-1.4 fine invoice bytes');
        $invoice->attachPdf($path);
        $invoice->popEvents();
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function findAdmin(): User
    {
        $admin = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'admin@example.com']);
        \assert($admin instanceof User);

        return $admin;
    }

    private function findFirstOnboardedOrder(): Order
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.individualMonthlyAmount IS NOT NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($contract instanceof Contract, 'Expected fixture: at least one contract with individualMonthlyAmount.');

        return $contract->order;
    }

    private function findVanillaContractOrder(): Order
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.individualMonthlyAmount IS NULL')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        \assert($contract instanceof Contract, 'Expected fixture: at least one contract without individualMonthlyAmount.');

        return $contract->order;
    }
}
