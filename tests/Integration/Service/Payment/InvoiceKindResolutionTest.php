<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service\Payment;

use App\DataFixtures\UserFixtures;
use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Storage;
use App\Entity\User;
use App\Enum\InvoiceKind;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Enum\StorageStatus;
use App\Service\Payment\PaymentMethodBackfill;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * PaymentMethodBackfill::resolveInvoiceKind() — same matching as the spec-093
 * payment-source resolver: a Payment without a billing period is the first
 * payment, one with a period a recurring cycle; credit notes / fines by FK.
 */
final class InvoiceKindResolutionTest extends KernelTestCase
{
    private const int AMOUNT = 308_000;

    private EntityManagerInterface $entityManager;
    private PaymentMethodBackfill $resolver;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine')->getManager();
        $this->resolver = $container->get(PaymentMethodBackfill::class);
    }

    public function testFirstPaymentAndRecurringCycleOfTheSameAmountAreToldApart(): void
    {
        // Order 019f5d05 shape: 3 080 Kč first payment, then 3 080 Kč cycles.
        $order = $this->createOrder();
        $firstAt = new \DateTimeImmutable('2025-06-10 09:00:00');
        $cycleAt = new \DateTimeImmutable('2025-07-11 05:00:00');
        $this->createPayment($order, $firstAt, period: false);
        $this->createPayment($order, $cycleAt, period: true);
        $firstInvoice = $this->createInvoice($order, self::AMOUNT, $firstAt, 1);
        $cycleInvoice = $this->createInvoice($order, self::AMOUNT, $cycleAt, 2);
        $this->entityManager->flush();

        self::assertSame(InvoiceKind::FIRST_PAYMENT, $this->resolver->resolveInvoiceKind($firstInvoice));
        self::assertSame(InvoiceKind::RECURRING, $this->resolver->resolveInvoiceKind($cycleInvoice));
    }

    public function testCreditNoteIsClassifiedByItsCorrection(): void
    {
        $order = $this->createOrder();
        $at = new \DateTimeImmutable('2025-06-10 09:00:00');
        $this->createPayment($order, $at, period: false);
        $original = $this->createInvoice($order, self::AMOUNT, $at, 3);
        $creditNote = $this->createInvoice($order, -self::AMOUNT, $at->modify('+3 days'), 4, $original);
        $this->entityManager->flush();

        self::assertSame(InvoiceKind::CREDIT_NOTE, $this->resolver->resolveInvoiceKind($creditNote));
    }

    public function testNothingMatchingWithin24HoursStaysUnknown(): void
    {
        $order = $this->createOrder();
        $this->createPayment($order, new \DateTimeImmutable('2025-06-10 09:00:00'), period: false);
        $late = $this->createInvoice($order, self::AMOUNT, new \DateTimeImmutable('2025-06-14 09:00:00'), 5);
        $this->entityManager->flush();

        self::assertNull($this->resolver->resolveInvoiceKind($late));
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
            firstPaymentPrice: self::AMOUNT,
            expiresAt: new \DateTimeImmutable('2025-07-10'),
            createdAt: new \DateTimeImmutable('2025-06-09'),
        );
        $order->setPaymentMethod(PaymentMethod::GOPAY);
        $order->markPaid(new \DateTimeImmutable('2025-06-10 09:00:00'), goPayPaymentId: 'gp-kind-'.Uuid::v7()->toBase58());
        $order->popEvents();
        $this->entityManager->persist($order);

        return $order;
    }

    private function createPayment(Order $order, \DateTimeImmutable $paidAt, bool $period): void
    {
        $payment = new Payment(
            id: Uuid::v7(),
            order: $order,
            contract: null,
            storage: $order->storage,
            amount: self::AMOUNT,
            paidAt: $paidAt,
            createdAt: $paidAt,
        );
        if ($period) {
            $payment->coverPeriod($paidAt, $paidAt->modify('+1 month'));
        }
        $this->entityManager->persist($payment);
    }

    private function createInvoice(Order $order, int $amount, \DateTimeImmutable $issuedAt, int $n, ?Invoice $correctionOf = null): Invoice
    {
        $invoice = new Invoice(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            fakturoidInvoiceId: 990000 + $n,
            invoiceNumber: 'KIND-'.$n.'-'.Uuid::v7()->toBase58(),
            amount: $amount,
            issuedAt: $issuedAt->modify('+1 second'),
            createdAt: $issuedAt,
            correctionOf: $correctionOf,
        );
        $invoice->popEvents();
        $this->entityManager->persist($invoice);

        return $invoice;
    }

    private function findTenant(): User
    {
        $user = $this->entityManager->createQueryBuilder()
            ->select('u')->from(User::class, 'u')
            ->where('u.email = :email')->setParameter('email', UserFixtures::TENANT_EMAIL)
            ->getQuery()->getOneOrNullResult();
        \assert($user instanceof User);

        return $user;
    }

    private function findAvailableStorage(): Storage
    {
        $storage = $this->entityManager->createQueryBuilder()
            ->select('s')->from(Storage::class, 's')
            ->where('s.status = :status')->setParameter('status', StorageStatus::AVAILABLE)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        \assert($storage instanceof Storage);

        return $storage;
    }
}
