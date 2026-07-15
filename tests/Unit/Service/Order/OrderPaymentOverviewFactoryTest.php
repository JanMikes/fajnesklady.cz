<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Order;

use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\Order\OrderPaymentOverviewFactory;
use App\Service\Order\PaymentOverviewRow;
use App\Service\PriceCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class OrderPaymentOverviewFactoryTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2025-06-15 12:00:00');
    }

    public function testExternallyPrepaidOnboardingOrderWithOverdueCycle(): void
    {
        // Mirrors the production case: admin-onboarded order, externally
        // prepaid through 2025-06-06, first in-system cycle unpaid and past due.
        $order = $this->createOrder(startDate: '2025-02-06', endDate: '2025-08-31');
        $order->markAsAdminCreated();
        $order->setPaymentMethod(PaymentMethod::EXTERNAL);
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->assignVariableSymbol('1234567890');
        $order->setOnboardingBillingTerms(288000, new \DateTimeImmutable('2025-06-06'));
        $order->markPaid($this->now, 0);

        $contract = $this->createContract($order);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->applyIndividualMonthlyAmount(288000, null, 'onboarding', $this->now);
        $contract->markExternallyPrepaid(new \DateTimeImmutable('2025-06-06'));

        $overdueRequest = new ManualPaymentRequest(
            id: Uuid::v7(),
            contract: $contract,
            periodStart: new \DateTimeImmutable('2025-06-07'),
            periodEnd: new \DateTimeImmutable('2025-07-06'),
            amount: 288000,
            createdAt: new \DateTimeImmutable('2025-06-08'),
        );

        $zeroFormalityPayment = new Payment(
            id: Uuid::v7(),
            order: $order,
            contract: null,
            storage: $order->storage,
            amount: 0,
            paidAt: $this->now,
            createdAt: $this->now,
        );

        $factory = $this->createFactory();
        $overview = $factory->build($order, $contract, [$zeroFormalityPayment], [$overdueRequest], $this->now);

        $statuses = array_map(static fn (PaymentOverviewRow $r): string => $r->status, $overview->rows);

        // Prepaid onboarding period is visible and explains itself.
        $prepaidRows = array_values(array_filter($overview->rows, static fn (PaymentOverviewRow $r): bool => PaymentOverviewRow::STATUS_COVERED_EXTERNAL === $r->status));
        $this->assertCount(1, $prepaidRows);
        $this->assertStringContainsString('externě', $prepaidRows[0]->label);

        // The 0 Kč formality payment adds no extra row.
        $this->assertNotContains('První platba', array_map(static fn (PaymentOverviewRow $r): string => $r->label, $overview->rows));

        // The unpaid cycle is overdue with day count and VS note.
        $overdueRows = array_values(array_filter($overview->rows, static fn (PaymentOverviewRow $r): bool => PaymentOverviewRow::STATUS_OVERDUE === $r->status));
        $this->assertCount(1, $overdueRows);
        $this->assertSame(288000, $overdueRows[0]->amountInHaler);
        $this->assertSame(8, $overdueRows[0]->daysOverdue);
        $this->assertNotNull($overdueRows[0]->note);
        $this->assertStringContainsString('VS 1234567890', $overdueRows[0]->note);

        // Future cycles are projected until the contract end.
        $this->assertContains(PaymentOverviewRow::STATUS_SCHEDULED, $statuses);

        // Totals: nothing actually paid, the overdue cycle is outstanding.
        $this->assertSame(0, $overview->totalPaidInHaler);
        $this->assertSame(288000, $overview->overdueTotalInHaler);
        $this->assertGreaterThan(288000, $overview->outstandingTotalInHaler);

        // Rows are chronological.
        $sortDates = array_map(static fn (PaymentOverviewRow $r): ?\DateTimeImmutable => $r->sortDate(), $overview->rows);
        $sorted = $sortDates;
        usort($sorted, static fn (?\DateTimeImmutable $a, ?\DateTimeImmutable $b): int => $a <=> $b);
        $this->assertSame($sorted, $sortDates);
    }

    public function testPaidGoPayOrderShowsFirstPaymentAndPaidCycles(): void
    {
        $order = $this->createOrder(startDate: '2025-05-01', endDate: '2025-09-30');
        $order->setPaymentMethod(PaymentMethod::GOPAY);
        $order->setBillingMode(BillingMode::AUTO_RECURRING);
        $order->markPaid(new \DateTimeImmutable('2025-05-01 10:00:00'));

        $contract = $this->createContract($order);
        $contract->applyBillingMode(BillingMode::AUTO_RECURRING);
        $contract->scheduleNextBilling(new \DateTimeImmutable('2025-07-01'), new \DateTimeImmutable('2025-06-30'));

        $firstPayment = new Payment(
            id: Uuid::v7(),
            order: $order,
            contract: null,
            storage: $order->storage,
            amount: 288000,
            paidAt: new \DateTimeImmutable('2025-05-01 10:00:00'),
            createdAt: new \DateTimeImmutable('2025-05-01 10:00:00'),
        );
        $firstPayment->setGoPayPaymentId('gp-1');

        $recurringCharge = new Payment(
            id: Uuid::v7(),
            order: null,
            contract: $contract,
            storage: $order->storage,
            amount: 288000,
            paidAt: new \DateTimeImmutable('2025-06-01 03:00:00'),
            createdAt: new \DateTimeImmutable('2025-06-01 03:00:00'),
        );
        $recurringCharge->setGoPayPaymentId('gp-2');

        $factory = $this->createFactory();
        $overview = $factory->build($order, $contract, [$firstPayment, $recurringCharge], [], $this->now);

        $paidRows = array_values(array_filter($overview->rows, static fn (PaymentOverviewRow $r): bool => PaymentOverviewRow::STATUS_PAID === $r->status));
        $this->assertCount(2, $paidRows);
        $this->assertSame('Kartou (GoPay)', $paidRows[0]->source);
        $this->assertSame(576000, $overview->totalPaidInHaler);

        // Upcoming card charges are projected from nextBillingDate.
        $scheduledRows = array_values(array_filter($overview->rows, static fn (PaymentOverviewRow $r): bool => PaymentOverviewRow::STATUS_SCHEDULED === $r->status));
        $this->assertNotEmpty($scheduledRows);
        $this->assertSame('Automaticky kartou', $scheduledRows[0]->source);
    }

    public function testPaidRequestIsNotDuplicatedByItsPaymentRow(): void
    {
        $order = $this->createOrder(startDate: '2025-04-01', endDate: '2025-09-30');
        $order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        $order->setBillingMode(BillingMode::MANUAL_RECURRING);
        $order->markPaid(new \DateTimeImmutable('2025-04-01 09:00:00'));

        $contract = $this->createContract($order);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->scheduleNextBilling(new \DateTimeImmutable('2025-07-01'), new \DateTimeImmutable('2025-06-30'));

        $paidAt = new \DateTimeImmutable('2025-06-02 08:00:00');
        $paidRequest = new ManualPaymentRequest(
            id: Uuid::v7(),
            contract: $contract,
            periodStart: new \DateTimeImmutable('2025-06-01'),
            periodEnd: new \DateTimeImmutable('2025-06-30'),
            amount: 288000,
            createdAt: new \DateTimeImmutable('2025-05-25'),
        );
        $paidRequest->markPaid($paidAt);

        // The Payment row created inside the same handler shares $now.
        $cyclePayment = new Payment(
            id: Uuid::v7(),
            order: null,
            contract: $contract,
            storage: $order->storage,
            amount: 288000,
            paidAt: $paidAt,
            createdAt: $paidAt,
        );

        $factory = $this->createFactory();
        $overview = $factory->build($order, $contract, [$cyclePayment], [$paidRequest], $this->now);

        $cycleRows = array_values(array_filter(
            $overview->rows,
            static fn (PaymentOverviewRow $r): bool => str_contains($r->label, '01.06.2025'),
        ));
        $this->assertCount(1, $cycleRows);
        $this->assertSame(PaymentOverviewRow::STATUS_PAID, $cycleRows[0]->status);
        $this->assertNotNull($cycleRows[0]->paidAt);
    }

    private function createFactory(): OrderPaymentOverviewFactory
    {
        return new OrderPaymentOverviewFactory(
            new RecurringAmountCalculator(new PriceCalculator()),
        );
    }

    private function createOrder(string $startDate, string $endDate): Order
    {
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', $this->now);
        $owner = new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', $this->now);

        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $this->now,
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 288000,
            defaultPricePerMonthLongTerm: 288000,
            defaultPricePerYear: 288000 * 12,
            createdAt: $this->now,
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $this->now,
            owner: $owner,
        );

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: new \DateTimeImmutable($startDate),
            endDate: new \DateTimeImmutable($endDate),
            firstPaymentPrice: 288000,
            expiresAt: new \DateTimeImmutable('2025-07-01'),
            createdAt: $this->now,
        );
    }

    private function createContract(Order $order): Contract
    {
        $endDate = $order->endDate;
        \assert(null !== $endDate);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            startDate: $order->startDate,
            endDate: $endDate,
            createdAt: $this->now,
        );
        $order->complete($contract->id, $this->now);

        return $contract;
    }
}
