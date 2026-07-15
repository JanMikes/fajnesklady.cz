<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\Contract;
use App\Entity\Fine;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\FineType;
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
        $fine->markPaid($now);
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
