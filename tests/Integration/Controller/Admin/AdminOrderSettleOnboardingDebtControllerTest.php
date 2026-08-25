<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Entity\AuditLog;
use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Enum\PaymentFrequency;
use App\Enum\SigningMethod;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Admin marks the onboarding debt (debt from the customer's previous,
 * pre-system contract) as paid off-system. Distinct from
 * {@see AdminOrderSettleDebtControllerTest}, which covers the post-termination
 * Contract.outstandingDebtAmount — a different debt with a different button.
 */
class AdminOrderSettleOnboardingDebtControllerTest extends WebTestCase
{
    private const string BUTTON_LABEL = 'Označit dluh z předchozí smlouvy jako uhrazený';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get('doctrine')->getManager();
        $this->clock = static::getContainer()->get(ClockInterface::class);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testSettleMarksDebtPaidIssuesInvoiceAndKeepsStandardOrderPayable(): void
    {
        $order = $this->createSignedOnboardingOrder(debtInHaler: 1_656_000);
        $this->entityManager->flush();
        $orderId = $order->id->toRfc4122();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($order));

        $this->assertResponseRedirects('/portal/admin/orders/'.$orderId);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Order::class, Uuid::fromString($orderId));
        $this->assertInstanceOf(Order::class, $reloaded);
        $this->assertNotNull($reloaded->debtPaidAt);
        $this->assertFalse($reloaded->hasUnpaidDebt());
        // Standard billing: first rent is still owed, so the order stays payable.
        $this->assertSame(OrderStatus::RESERVED, $reloaded->status);

        $this->assertNotNull($this->findAuditRow($orderId, 'onboarding_debt_settled'));
        $this->assertNotNull($this->findAuditRow($orderId, 'debt_payment_confirmed'));
        // OnboardingDebtPaid → SendOnboardingDebtPaidEmailHandler issues the debt invoice (mock Fakturoid).
        $this->assertCount(1, $this->findInvoicesForOrder($orderId));
    }

    public function testSettlePrepaidOrderCompletesItAndCreatesContract(): void
    {
        $now = $this->clock->now();
        $order = $this->createSignedOnboardingOrder(debtInHaler: 50_000, paidThroughDate: $now->modify('+3 months'));
        $this->entityManager->flush();
        $orderId = $order->id->toRfc4122();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($order));

        $this->assertResponseRedirects('/portal/admin/orders/'.$orderId);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Order::class, Uuid::fromString($orderId));
        $this->assertInstanceOf(Order::class, $reloaded);
        $this->assertFalse($reloaded->hasUnpaidDebt());
        // The debt was the only blocker of an externally prepaid onboarding → auto-completed.
        $this->assertSame(OrderStatus::COMPLETED, $reloaded->status);
        $this->assertNotNull($this->findContractForOrder($orderId));
    }

    public function testRejectsWhenOrderHasNoDebt(): void
    {
        $order = $this->createSignedOnboardingOrder(debtInHaler: 0);
        $this->entityManager->flush();
        $orderId = $order->id->toRfc4122();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($order));

        $this->assertResponseRedirects('/portal/admin/orders/'.$orderId);
        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->get('error');
        $this->assertNotEmpty($flashes);
        $this->assertNull($this->findAuditRow($orderId, 'onboarding_debt_settled'));
    }

    public function testRejectsWhenDebtAlreadyPaid(): void
    {
        $order = $this->createSignedOnboardingOrder(debtInHaler: 50_000);
        $paidAt = $this->clock->now()->modify('-1 day');
        $order->markDebtPaid($paidAt);
        $order->popEvents();
        $this->entityManager->flush();
        $orderId = $order->id->toRfc4122();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($order));

        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->get('error');
        $this->assertNotEmpty($flashes);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Order::class, Uuid::fromString($orderId));
        $this->assertInstanceOf(Order::class, $reloaded);
        $this->assertEquals($paidAt, $reloaded->debtPaidAt, 'an already-paid debt must keep its original paid-at');
        $this->assertNull($this->findAuditRow($orderId, 'onboarding_debt_settled'));
    }

    public function testDetailPageRendersActionForUnpaidOnboardingDebt(): void
    {
        $order = $this->createSignedOnboardingOrder(debtInHaler: 1_656_000);
        $this->entityManager->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/portal/admin/orders/'.$order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', self::BUTTON_LABEL);
        $this->assertSelectorExists('#settleOnboardingDebtModal');
        $this->assertSelectorTextContains('#settleOnboardingDebtModal', '16 560 Kč');
        // Standard billing → no "auto-complete" warning.
        $this->assertSelectorTextNotContains('#settleOnboardingDebtModal', 'rovnou dokončí');
    }

    public function testDetailPageWarnsThatPrepaidOrderWillComplete(): void
    {
        $order = $this->createSignedOnboardingOrder(debtInHaler: 50_000, paidThroughDate: new \DateTimeImmutable('2026-09-01'));
        $this->entityManager->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/portal/admin/orders/'.$order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#settleOnboardingDebtModal', 'předplacená do 01.09.2026');
        $this->assertSelectorTextContains('#settleOnboardingDebtModal', 'rovnou dokončí');
    }

    public function testDetailPageHidesActionOnceDebtIsPaid(): void
    {
        $order = $this->createSignedOnboardingOrder(debtInHaler: 50_000);
        $order->markDebtPaid($this->clock->now());
        $order->popEvents();
        $this->entityManager->flush();

        $this->loginAsAdmin();
        $this->client->request('GET', '/portal/admin/orders/'.$order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('body', self::BUTTON_LABEL);
        $this->assertSelectorNotExists('#settleOnboardingDebtModal');
    }

    public function testRequiresAuthentication(): void
    {
        $order = $this->createSignedOnboardingOrder(debtInHaler: 50_000);
        $this->entityManager->flush();

        $this->client->request('POST', $this->url($order));

        $this->assertResponseRedirects('/login');
    }

    public function testDeniedForNonAdmin(): void
    {
        $order = $this->createSignedOnboardingOrder(debtInHaler: 50_000);
        $this->entityManager->flush();

        $this->client->loginUser($this->findUserByEmail('user@example.com'), 'main');
        $this->client->request('POST', $this->url($order));

        $this->assertResponseStatusCodeSame(403);
    }

    private function url(Order $order): string
    {
        return '/portal/admin/orders/'.$order->id->toRfc4122().'/settle-onboarding-debt';
    }

    private function loginAsAdmin(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
    }

    /**
     * Admin-created, customer-signed, RESERVED onboarding order — the state the
     * order sits in while its previous-contract debt is unpaid.
     */
    private function createSignedOnboardingOrder(int $debtInHaler, ?\DateTimeImmutable $paidThroughDate = null): Order
    {
        $now = $this->clock->now();
        $tenant = $this->findUserByEmail('tenant@example.com');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Onboarding-debt place',
            address: 'Testovací 1',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $now,
        );
        $this->entityManager->persist($place);

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Onboarding-debt type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: $now,
        );
        $this->entityManager->persist($storageType);

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'ODB1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $now,
        );
        $this->entityManager->persist($storage);

        $order = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $now->modify('first day of next month')->setTime(0, 0),
            endDate: $now->modify('first day of next month')->modify('+12 months')->modify('-1 day')->setTime(0, 0),
            firstPaymentPrice: 35000,
            expiresAt: $now->modify('+30 days'),
            createdAt: $now->modify('-7 days'),
        );
        $order->markAsAdminCreated();
        $order->setOnboardingBillingTerms(35000, $paidThroughDate);
        if ($debtInHaler > 0) {
            $order->setOnboardingDebt($debtInHaler);
        }
        $order->attachSignature('signatures/odb.png', SigningMethod::TYPED, 'Jan Novák', null, 'Praha', $now);
        $order->acceptTerms($now);
        $order->reserve($now);
        $order->popEvents();
        $this->entityManager->persist($order);

        return $order;
    }

    private function findContractForOrder(string $orderId): ?Contract
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Contract::class, 'c')
            ->join('c.order', 'o')
            ->where('o.id = :orderId')
            ->setParameter('orderId', $orderId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Invoice>
     */
    private function findInvoicesForOrder(string $orderId): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('i')
            ->from(Invoice::class, 'i')
            ->join('i.order', 'o')
            ->where('o.id = :orderId')
            ->setParameter('orderId', $orderId)
            ->getQuery()
            ->getResult();
    }

    private function findAuditRow(string $entityId, string $eventType): ?AuditLog
    {
        return $this->entityManager->createQueryBuilder()
            ->select('al')
            ->from(AuditLog::class, 'al')
            ->where('al.eventType = :eventType')
            ->andWhere('al.entityId = :entityId')
            ->setParameter('eventType', $eventType)
            ->setParameter('entityId', $entityId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function findUserByEmail(string $email): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->where('u.email = :email')
            ->setParameter('email', $email)
            ->getQuery()
            ->getOneOrNullResult();
        \assert($user instanceof User);

        return $user;
    }
}
