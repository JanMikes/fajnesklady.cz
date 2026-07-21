<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Portal\User;

use App\DataFixtures\UserFixtures;
use App\Entity\Contract;
use App\Entity\Fine;
use App\Entity\Invoice;
use App\Entity\ManualPaymentRequest;
use App\Entity\Order;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\FineType;
use App\Enum\OrderStatus;
use App\Enum\PaymentFrequency;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

class OrderDetailControllerTest extends WebTestCase
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

    public function testOwnerSeesOrderWithDocumentsCard(): void
    {
        $order = $this->findCompletedOrder();
        $this->client->loginUser($order->user, 'main');

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Detail objednávky');
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Vaše dokumenty', $body);
        $this->assertStringContainsString('id="dokumenty"', $body);
    }

    public function testOtherUserCannotAccessOthersOrder(): void
    {
        $order = $this->findCompletedOrder();
        $otherUser = $this->findUserByEmail(UserFixtures::TENANT_EMAIL);
        $this->assertFalse($order->user->id->equals($otherUser->id));

        $this->client->loginUser($otherUser, 'main');
        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminCannotAccessUserOrderDetail(): void
    {
        // Admins use /portal/admin/orders/{id}; the user-portal route is owner-only.
        $order = $this->findCompletedOrder();
        $admin = $this->findUserByEmail(UserFixtures::ADMIN_EMAIL);

        $this->client->loginUser($admin, 'main');
        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $order = $this->findCompletedOrder();

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseRedirects();
        $this->assertStringContainsString('login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testFreeContractRendersZdarmaBadge(): void
    {
        // OnboardingFixtures: tenant onboarded on storage P2 with individualMonthlyAmount = 0.
        $order = $this->findOnboardedOrderByStorageNumber('P2');
        $this->client->loginUser($order->user, 'main');

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Pronájem zdarma', $body);
        $this->assertStringContainsString('Tato smlouva nepodléhá platbám.', $body);
    }

    public function testExternalPrepaymentInFutureRendersBlueBanner(): void
    {
        // OnboardingFixtures: tenant onboarded on storage O2 with paidThroughDate = today + 30d.
        $order = $this->findOnboardedOrderByStorageNumber('O2');
        $this->client->loginUser($order->user, 'main');

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Předplaceno externě do', $body);
        $this->assertStringContainsString('Po tomto datu se obnoví běžné měsíční platby.', $body);
        $this->assertStringNotContainsString('Externí předplatné brzy končí.', $body);
    }

    public function testExternalPrepaymentEndingSoonRendersAmberBanner(): void
    {
        // OnboardingFixtures: tenant onboarded on storage E2 with paidThroughDate = today + 5d.
        $order = $this->findOnboardedOrderByStorageNumber('E2');
        $this->client->loginUser($order->user, 'main');

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Externí předplatné brzy končí.', $body);
        $this->assertStringContainsString('simek@fajnesklady.cz', $body);
    }

    public function testVanillaContractDoesNotRenderAnyBillingStatusBanner(): void
    {
        $order = $this->findCompletedOrder();
        $this->client->loginUser($order->user, 'main');

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('Pronájem zdarma', $body);
        $this->assertStringNotContainsString('Předplaceno externě', $body);
        $this->assertStringNotContainsString('Externí předplatné brzy končí', $body);
    }

    public function testPaidFineWithInvoiceShowsInvoiceDownloadLink(): void
    {
        $order = $this->findCompletedOrder();
        $fine = $this->createPaidFine($order);
        $invoice = $this->createFineInvoice($fine, withPdf: true);
        $this->client->loginUser($order->user, 'main');

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        // Fines panel link → portal invoice PDF download.
        $this->assertStringContainsString('/portal/faktury/'.$invoice->id->toRfc4122().'/pdf?download=1', $body);
        // Docs panel labels the fine invoice distinctly.
        $this->assertStringContainsString('(smluvní pokuta)', $body);
        $this->assertStringContainsString('Doklad o zaplacení smluvní pokuty', $body);
    }

    public function testPaidFineWithoutInvoiceRendersWithoutInvoiceLink(): void
    {
        // Pre-feature fines have no invoice — the row must render without a link.
        $order = $this->findCompletedOrder();
        $this->createPaidFine($order);
        $this->client->loginUser($order->user, 'main');

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Zaplaceno', $body);
        $this->assertStringNotContainsString('(smluvní pokuta)', $body);
    }

    public function testManualBillingCycleWithoutCreditShowsTheFullAmount(): void
    {
        $order = $this->createManualBillingOrderWithPendingCycle(credit: 0);
        $this->client->loginUser($order->user, 'main');

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Platba k zaplacení:', $body);
        $this->assertStringContainsString('3 100,00 Kč vč. DPH', $body);
        $this->assertStringContainsString('Variabilní symbol:', $body);
        $this->assertStringContainsString('data:image/png;base64,', $body);
    }

    public function testManualBillingCycleSubtractsContractCreditFromTheRequestedAmount(): void
    {
        // Spec 091 D3: 400 Kč credit against a 3 100 Kč cycle → ask for 2 700 Kč.
        $order = $this->createManualBillingOrderWithPendingCycle(credit: 40_000);
        $this->client->loginUser($order->user, 'main');

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('2 700,00 Kč vč. DPH', $body);
        $this->assertStringNotContainsString('3 100,00 Kč vč. DPH', $body);
        // The persisted request row keeps the full cycle — this is render-time only.
        $this->assertSame(310_000, $this->pendingRequestFor($order)->amount);
    }

    public function testManualBillingCycleFullyCoveredByCreditRendersNoPaymentInstruction(): void
    {
        $order = $this->createManualBillingOrderWithPendingCycle(credit: 310_000);
        $this->client->loginUser($order->user, 'main');

        $this->client->request('GET', $this->buildUrl($order));

        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        // A 0 Kč QR is a valid but nonsensical instruction — drop the whole block.
        $this->assertStringNotContainsString('Platba k zaplacení:', $body);
        $this->assertStringNotContainsString('0,00 Kč vč. DPH', $body);
        $this->assertStringContainsString('Platby probíhají ručně.', $body);
    }

    private function pendingRequestFor(Order $order): ManualPaymentRequest
    {
        $request = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(ManualPaymentRequest::class, 'r')
            ->join('r.contract', 'c')
            ->where('c.order = :order')
            ->setParameter('order', $order)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($request instanceof ManualPaymentRequest, 'No pending manual payment request for order.');

        return $request;
    }

    /**
     * A MANUAL_RECURRING contract mid-term (no proration) whose current cycle
     * has a pending payment request frozen at the full 3 100 Kč.
     */
    private function createManualBillingOrderWithPendingCycle(int $credit): Order
    {
        $source = $this->findCompletedOrder();

        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $startDate = new \DateTimeImmutable('2025-01-01');
        $endDate = new \DateTimeImmutable('2026-06-30');
        // findPendingForCurrentCycle() wants periodEnd >= now, so the cycle in
        // flight at MockClock time is the June one.
        $periodStart = new \DateTimeImmutable('2025-06-01');

        $order = new Order(
            id: Uuid::v7(),
            user: $source->user,
            storage: $source->storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: 310_000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->assignVariableSymbol('9100000456');
        $order->popEvents();
        $this->entityManager->persist($order);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: $now,
        );
        $contract->sign($now);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->applyPaymentFrequency(PaymentFrequency::MONTHLY);
        // Pin the price so the cycle amount does not depend on the storage rate.
        $contract->applyIndividualMonthlyAmount(310_000, null, null, $now);
        $contract->scheduleNextBilling($periodStart, null);
        if ($credit > 0) {
            $contract->addCredit($credit);
        }
        $contract->popEvents();
        $this->entityManager->persist($contract);

        $request = new ManualPaymentRequest(
            id: Uuid::v7(),
            contract: $contract,
            periodStart: $periodStart,
            periodEnd: new \DateTimeImmutable('2025-06-30'),
            amount: 310_000,
            createdAt: $now,
        );
        $this->entityManager->persist($request);
        $this->entityManager->flush();

        return $order;
    }

    private function createPaidFine(Order $order): Fine
    {
        $contract = $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->where('c.order = :order')
            ->setParameter('order', $order)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($contract instanceof Contract, 'Fixture order must have a contract.');

        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $fine = new Fine(
            id: Uuid::v7(),
            contract: $contract,
            user: $contract->user,
            issuedBy: $this->findUserByEmail(UserFixtures::ADMIN_EMAIL),
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

    private function createFineInvoice(Fine $fine, bool $withPdf): Invoice
    {
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $invoice = new Invoice(
            id: Uuid::v7(),
            order: $fine->contract->order,
            user: $fine->user,
            fakturoidInvoiceId: 987654,
            invoiceNumber: 'FV-2025-FINE-1',
            amount: $fine->amountInHaler,
            issuedAt: $now,
            createdAt: $now,
            fine: $fine,
        );
        if ($withPdf) {
            $path = tempnam(sys_get_temp_dir(), 'fine_invoice_');
            \assert(is_string($path));
            file_put_contents($path, '%PDF-1.4 fine invoice bytes');
            $invoice->attachPdf($path);
        }
        $invoice->popEvents();
        $this->entityManager->persist($invoice);
        $this->entityManager->flush();

        return $invoice;
    }

    private function buildUrl(Order $order): string
    {
        return '/portal/objednavky/'.$order->id->toRfc4122();
    }

    private function findOnboardedOrderByStorageNumber(string $storageNumber): Order
    {
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->join('o.storage', 's')
            ->where('s.number = :number')
            ->setParameter('number', $storageNumber)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($order instanceof Order, sprintf('No onboarded order on storage %s', $storageNumber));

        return $order;
    }

    private function findCompletedOrder(): Order
    {
        // Pin to USER so non-owner tests using TENANT are deterministic.
        $user = $this->findUserByEmail(UserFixtures::USER_EMAIL);
        $orders = $this->entityManager->getRepository(Order::class)->findBy([
            'status' => OrderStatus::COMPLETED,
            'user' => $user,
        ]);
        foreach ($orders as $order) {
            if (null !== $order->endDate) {
                return $order;
            }
        }

        throw new \LogicException('No completed limited-term order owned by USER fixture.');
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        \assert($user instanceof User, sprintf('User "%s" not found in fixtures', $email));

        return $user;
    }
}
