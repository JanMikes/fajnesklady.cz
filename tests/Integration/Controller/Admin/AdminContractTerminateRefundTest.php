<?php

declare(strict_types=1);

namespace App\Tests\Integration\Controller\Admin;

use App\Command\ProcessPaymentNotificationCommand;
use App\Entity\AuditLog;
use App\Entity\Contract;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\PaymentRefund;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentRefundStatus;
use App\Enum\RefundMode;
use App\Repository\PaymentRepository;
use App\Tests\Mock\MockFakturoidClient;
use App\Tests\Mock\MockGoPayClient;
use App\Value\GoPayRefundHistoryEntry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 092 — refunding the card payment when an admin terminates a contract.
 *
 * Fixture: MockClock 2025-06-15 12:00 UTC. The latest card payment (350 Kč)
 * paid the period 2025-06-10 → 2025-07-10 (30 days, end exclusive).
 */
class AdminContractTerminateRefundTest extends WebTestCase
{
    private const string GOPAY_ID = 'gp_refund_test_1';

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ClockInterface $clock;
    private MockGoPayClient $goPay;
    private MockFakturoidClient $fakturoid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->clock = $container->get(ClockInterface::class);
        $this->goPay = $container->get(MockGoPayClient::class);
        $this->goPay->reset();
        $this->fakturoid = $container->get(MockFakturoidClient::class);
        $this->fakturoid->reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        static::ensureKernelShutdown();
    }

    public function testProratedRefundWithCreditNote(): void
    {
        [$contract, $payment, $invoice] = $this->createCardPaidContract();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'immediate',
            'reason' => 'Přechod na sklad s jinými podmínkami',
            'password' => 'password',
            'refund' => '1',
            'refund_payment_id' => $payment->id->toRfc4122(),
            'refund_mode' => 'prorated',
            'refund_from' => '2025-06-15',
            'refund_credit_note' => '1',
        ]);

        $this->assertResponseRedirects('/portal/admin/orders/'.$contract->order->id->toRfc4122());
        $success = $this->client->getRequest()->getSession()->getFlashBag()->get('success');
        $this->assertNotEmpty($success);
        $this->assertStringContainsString('vráceno 292 Kč', $success[0]);

        // 25 of 30 days (15.6.–9.7.) of 350 Kč = 291.67 → whole koruny 292 Kč.
        $this->assertSame([['paymentId' => self::GOPAY_ID, 'amount' => 29200]], $this->goPay->getRefundCalls());

        $this->entityManager->clear();
        $contract = $this->entityManager->find(Contract::class, $contract->id);
        \assert($contract instanceof Contract);
        $this->assertTrue($contract->isTerminated());

        $refund = $this->findSingleRefund($contract);
        $this->assertSame(PaymentRefundStatus::SUCCEEDED, $refund->status);
        $this->assertSame(RefundMode::PRORATED, $refund->mode);
        $this->assertSame(29200, $refund->amount);
        $this->assertSame('Přechod na sklad s jinými podmínkami', $refund->reason);

        // Ledger: a storno row nets the order's revenue.
        $paymentRepository = static::getContainer()->get(PaymentRepository::class);
        // Two 350 Kč card payments in the fixture, minus the 292 Kč storno.
        $this->assertSame(70000 - 29200, $paymentRepository->sumPaidForOrder($contract->order, $contract));

        // Credit note linked to the original invoice, attached to the e-mail.
        $this->assertNotNull($refund->creditNote);
        $this->assertSame($invoice->id->toRfc4122(), $refund->creditNote->correctionOf?->id->toRfc4122());
        $this->assertSame(-29200, $refund->creditNote->amount);
        $this->assertTrue($refund->creditNote->isEmailed(), 'standalone "Faktura" e-mail must stay silent');
        $creditNotes = $this->fakturoid->getCreatedCreditNotes();
        $this->assertCount(1, $creditNotes);
        $this->assertSame($invoice->fakturoidInvoiceId, array_values($creditNotes)[0]['correctedInvoiceId']);

        $email = $this->findEmail('Vrácení platby — Fajnesklady.cz');
        $this->assertNotNull($email);
        $this->assertCount(1, $email->getAttachments());

        // History tells the story.
        $events = $this->auditEvents($contract->order);
        $this->assertContains('payment_refund.requested', $events);
        $this->assertContains('payment_refund.succeeded', $events);
        $this->assertContains('payment_refund.credit_note_issued', $events);

        // And the order detail renders the refund.
        $this->client->request('GET', '/portal/admin/orders/'.$contract->order->id->toRfc4122());
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Vrácení platby na kartu', $html);
        $this->assertStringContainsString('Platba vrácena na kartu', $html);
        $this->assertStringContainsString('Přechod na sklad s jinými podmínkami', $html);
    }

    public function testFullRefundWithoutCreditNote(): void
    {
        [$contract, $payment] = $this->createCardPaidContract();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'immediate',
            'password' => 'password',
            'refund' => '1',
            'refund_payment_id' => $payment->id->toRfc4122(),
            'refund_mode' => 'full',
        ]);

        $this->assertResponseRedirects();
        $this->assertSame(35000, $this->goPay->getRefundCalls()[0]['amount']);
        $this->assertSame([], $this->fakturoid->getCreatedCreditNotes());

        $this->entityManager->clear();
        $contract = $this->reload($contract);
        $refund = $this->findSingleRefund($contract);
        $this->assertSame(RefundMode::FULL, $refund->mode);
        $this->assertNull($refund->creditNote);

        $email = $this->findEmail('Vrácení platby — Fajnesklady.cz');
        $this->assertNotNull($email);
        $this->assertCount(0, $email->getAttachments());
    }

    public function testAcceptedRefundIsConfirmedByWebhook(): void
    {
        [$contract, $payment] = $this->createCardPaidContract();
        $this->goPay->willReturnRefundResult('ACCEPTED');

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'immediate',
            'password' => 'password',
            'refund' => '1',
            'refund_payment_id' => $payment->id->toRfc4122(),
            'refund_mode' => 'full',
        ]);

        $success = $this->client->getRequest()->getSession()->getFlashBag()->get('success');
        $this->assertStringContainsString('přijal ke zpracování', $success[0]);

        // No clear() here: the admin in the security token must stay managed
        // for the audit rows the webhook writes below.
        $this->assertSame(PaymentRefundStatus::PENDING, $this->findSingleRefund($contract)->status);
        // Not booked until GoPay confirms — both fixture payments still count.
        $this->assertSame(70000, static::getContainer()->get(PaymentRepository::class)->sumPaidForOrder($contract->order, $contract));

        // GoPay finishes the refund and notifies us.
        $this->goPay->settleRequestedRefunds(self::GOPAY_ID, GoPayRefundHistoryEntry::STATE_SUCCESS);
        $this->commandBus()->dispatch(new ProcessPaymentNotificationCommand(self::GOPAY_ID));

        $this->entityManager->clear();
        $contract = $this->reload($contract);
        $this->assertSame(PaymentRefundStatus::SUCCEEDED, $this->findSingleRefund($contract)->status);
        $this->assertSame(35000, static::getContainer()->get(PaymentRepository::class)->sumPaidForOrder($contract->order, $contract));
    }

    public function testDeclinedRefundKeepsTerminationAndRecordsFailure(): void
    {
        [$contract, $payment] = $this->createCardPaidContract();
        $this->goPay->willReturnRefundResult('FAILED');

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'immediate',
            'password' => 'password',
            'refund' => '1',
            'refund_payment_id' => $payment->id->toRfc4122(),
            'refund_mode' => 'full',
        ]);

        $warnings = $this->client->getRequest()->getSession()->getFlashBag()->get('warning');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('vrácení platby selhalo', $warnings[0]);

        $this->entityManager->clear();
        $contract = $this->reload($contract);
        $this->assertTrue($contract->isTerminated());
        $this->assertSame([], $this->findRefunds($contract));
        $this->assertSame(70000, static::getContainer()->get(PaymentRepository::class)->sumPaidForOrder($contract->order, $contract));
        $this->assertContains('payment_refund.request_failed', $this->auditEvents($contract->order));
    }

    public function testStalePaymentIdTerminatesNothing(): void
    {
        [$contract] = $this->createCardPaidContract();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'immediate',
            'password' => 'password',
            'refund' => '1',
            'refund_payment_id' => Uuid::v7()->toRfc4122(),
            'refund_mode' => 'full',
        ]);

        $this->assertNotEmpty($this->client->getRequest()->getSession()->getFlashBag()->get('error'));
        $this->assertSame([], $this->goPay->getRefundCalls());

        $this->entityManager->clear();
        $this->assertFalse($this->reload($contract)->isTerminated());
    }

    public function testRefundFromAfterPeriodEndTerminatesNothing(): void
    {
        [$contract, $payment] = $this->createCardPaidContract();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'immediate',
            'password' => 'password',
            'refund' => '1',
            'refund_payment_id' => $payment->id->toRfc4122(),
            'refund_mode' => 'prorated',
            'refund_from' => '2025-07-10',
        ]);

        $this->assertNotEmpty($this->client->getRequest()->getSession()->getFlashBag()->get('error'));
        $this->assertSame([], $this->goPay->getRefundCalls());

        $this->entityManager->clear();
        $this->assertFalse($this->reload($contract)->isTerminated());
    }

    public function testRefundIgnoredForTerminationWithNotice(): void
    {
        [$contract, $payment] = $this->createCardPaidContract();

        $this->loginAsAdmin();
        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'with_notice',
            'password' => 'password',
            'refund' => '1',
            'refund_payment_id' => $payment->id->toRfc4122(),
            'refund_mode' => 'full',
        ]);

        $this->assertNotEmpty($this->client->getRequest()->getSession()->getFlashBag()->get('success'));
        $this->assertSame([], $this->goPay->getRefundCalls());
    }

    public function testBankTransferPaymentCannotBeRefunded(): void
    {
        [$contract, $payment] = $this->createCardPaidContract(viaGoPay: false);

        $this->loginAsAdmin();
        $this->client->request('GET', '/portal/admin/orders/'.$contract->order->id->toRfc4122());
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Vrátit platbu kartou', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'immediate',
            'password' => 'password',
            'refund' => '1',
            'refund_payment_id' => $payment->id->toRfc4122(),
            'refund_mode' => 'full',
        ]);

        $this->assertNotEmpty($this->client->getRequest()->getSession()->getFlashBag()->get('error'));
        $this->assertSame([], $this->goPay->getRefundCalls());
    }

    public function testOrderDetailOffersRefundForCardPayment(): void
    {
        [$contract] = $this->createCardPaidContract();

        $this->loginAsAdmin();
        $this->client->request('GET', '/portal/admin/orders/'.$contract->order->id->toRfc4122());

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Vrátit platbu kartou', $html);
        $this->assertStringContainsString('Vystavit dobropis k faktuře', $html);
        $this->assertStringContainsString('10.06.2025 – 09.07.2025 (30 dní)', $html);
    }

    public function testRefundIssuedInGoPayConsoleIsBookedOnceFromWebhook(): void
    {
        [$contract] = $this->createCardPaidContract();
        $this->goPay->simulateExternalRefund(self::GOPAY_ID, 10000);

        // GoPay re-delivers notifications — the second one must book nothing.
        $this->commandBus()->dispatch(new ProcessPaymentNotificationCommand(self::GOPAY_ID));
        $this->commandBus()->dispatch(new ProcessPaymentNotificationCommand(self::GOPAY_ID));

        $this->entityManager->clear();
        $contract = $this->reload($contract);
        $refunds = $this->findRefunds($contract);
        $this->assertCount(1, $refunds);
        $this->assertSame(RefundMode::EXTERNAL, $refunds[0]->mode);
        $this->assertSame(10000, $refunds[0]->amount);
        $this->assertTrue($refunds[0]->isSucceeded());
        $this->assertSame(60000, static::getContainer()->get(PaymentRepository::class)->sumPaidForOrder($contract->order, $contract));
        $this->assertContains('payment_refund.refunded_externally', $this->auditEvents($contract->order));

        // No customer e-mail for a refund someone else issued in GoPay.
        $this->assertNull($this->findEmail('Vrácení platby — Fajnesklady.cz'));
    }

    public function testNonAdminCannotRefund(): void
    {
        [$contract, $payment] = $this->createCardPaidContract();

        $this->client->loginUser($this->findUserByEmail('landlord@example.com'), 'main');
        $this->client->request('POST', $this->url($contract), [
            'termination_type' => 'immediate',
            'password' => 'password',
            'refund' => '1',
            'refund_payment_id' => $payment->id->toRfc4122(),
            'refund_mode' => 'full',
        ]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertSame([], $this->goPay->getRefundCalls());
    }

    /**
     * @return array{0: Contract, 1: Payment, 2: Invoice}
     */
    private function createCardPaidContract(bool $viaGoPay = true): array
    {
        $now = $this->clock->now();
        $tenant = $this->findUserByEmail('tenant@example.com');

        $place = new Place(
            id: Uuid::v7(),
            name: 'Refund-test place',
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
            name: 'Refund-test type',
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
            number: 'RFD',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $now,
        );
        $this->entityManager->persist($storage);

        $start = new \DateTimeImmutable('2025-04-10 00:00:00');
        $order = new Order(
            id: Uuid::v7(),
            user: $tenant,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $start,
            endDate: $start->modify('+12 months'),
            firstPaymentPrice: 35000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $start,
        );
        $this->entityManager->persist($order);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $tenant,
            storage: $storage,
            startDate: $start,
            endDate: $start->modify('+12 months'),
            createdAt: $start,
        );
        $paidAt = new \DateTimeImmutable('2025-06-10 07:00:00');
        $contract->recordBillingCharge($paidAt, new \DateTimeImmutable('2025-07-10 00:00:00'), new \DateTimeImmutable('2025-07-10 00:00:00'));
        $this->entityManager->persist($contract);

        // Older, consumed card payment — must never be the refund candidate.
        $older = new Payment(Uuid::v7(), null, $contract, $storage, 35000, $paidAt->modify('-1 month'), $paidAt->modify('-1 month'));
        $older->setGoPayPaymentId('gp_refund_test_old');
        $older->coverPeriod(new \DateTimeImmutable('2025-05-10'), new \DateTimeImmutable('2025-06-10'));
        $this->entityManager->persist($older);

        $payment = new Payment(Uuid::v7(), null, $contract, $storage, 35000, $paidAt, $paidAt);
        if ($viaGoPay) {
            $payment->setGoPayPaymentId(self::GOPAY_ID);
            $this->goPay->seedRecurrenceStatus(self::GOPAY_ID, 'PAID', 'gp_parent', 35000);
        }
        $payment->coverPeriod(new \DateTimeImmutable('2025-06-10'), new \DateTimeImmutable('2025-07-10'));
        $this->entityManager->persist($payment);

        $invoice = new Invoice(
            id: Uuid::v7(),
            order: $order,
            user: $tenant,
            fakturoidInvoiceId: 777001,
            invoiceNumber: '0042202506',
            amount: 35000,
            issuedAt: $paidAt,
            createdAt: $paidAt,
        );
        $invoice->markEmailed($paidAt);
        $this->entityManager->persist($invoice);

        $this->entityManager->flush();

        return [$contract, $payment, $invoice];
    }

    private function reload(Contract $contract): Contract
    {
        $reloaded = $this->entityManager->find(Contract::class, $contract->id);
        \assert($reloaded instanceof Contract);

        return $reloaded;
    }

    /**
     * @return list<PaymentRefund>
     */
    private function findRefunds(Contract $contract): array
    {
        return $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PaymentRefund::class, 'r')
            ->where('r.order = :order')
            ->setParameter('order', $contract->order)
            ->getQuery()
            ->getResult();
    }

    private function findSingleRefund(Contract $contract): PaymentRefund
    {
        $refunds = $this->findRefunds($contract);
        $this->assertCount(1, $refunds);

        return $refunds[0];
    }

    /**
     * @return list<string> "entity.event" keys of the order's audit trail
     */
    private function auditEvents(Order $order): array
    {
        /** @var list<AuditLog> $logs */
        $logs = $this->entityManager->createQueryBuilder()
            ->select('a')
            ->from(AuditLog::class, 'a')
            ->where('a.orderId = :orderId')
            ->setParameter('orderId', $order->id)
            ->getQuery()
            ->getResult();

        return array_map(static fn (AuditLog $log): string => $log->entityType.'.'.$log->eventType, $logs);
    }

    private function findEmail(string $subject): ?TemplatedEmail
    {
        $transport = static::getContainer()->get('test.messenger.transport.async');
        \assert($transport instanceof InMemoryTransport);

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendEmailMessage) {
                $email = $message->getMessage();
                if ($email instanceof TemplatedEmail && $email->getSubject() === $subject) {
                    return $email;
                }
            }
        }

        return null;
    }

    private function commandBus(): MessageBusInterface
    {
        return static::getContainer()->get('test.command.bus');
    }

    private function url(Contract $contract): string
    {
        return '/portal/admin/contracts/'.$contract->id->toRfc4122().'/terminate';
    }

    private function loginAsAdmin(): void
    {
        $this->client->loginUser($this->findUserByEmail('admin@example.com'), 'main');
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
