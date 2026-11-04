<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Service\Billing\RecurringAmountCalculator;
use App\Service\PriceCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Production incident 2026-11-03 (order 019f98cd): a 1 500 Kč/month manual
 * contract ending 25.11 with its billing anchor at 01.11 15:15:48 (the time of
 * day of the first payment).
 */
class RecurringAmountCalculatorOpenCycleTest extends TestCase
{
    private RecurringAmountCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new RecurringAmountCalculator(new PriceCalculator());
    }

    /**
     * 01.11 → 25.11 is 24 days. diff()->days from 15:15:48 floored it to 23,
     * so the customer was asked for 1 150 Kč instead of 1 200 Kč.
     */
    public function testProrationCountsWholeDaysRegardlessOfTheAnchorsTimeOfDay(): void
    {
        $contract = $this->createMonthlyContract(
            startDate: new \DateTimeImmutable('2026-07-25'),
            endDate: new \DateTimeImmutable('2026-11-25'),
            nextBillingDate: new \DateTimeImmutable('2026-11-01 15:15:48'),
        );
        $contract->applyIndividualMonthlyAmount(150_000, null, null, new \DateTimeImmutable('2026-07-01'));

        self::assertSame(120_000, $this->calculator->calculate($contract, new \DateTimeImmutable('2026-10-25 05:00:00')));
    }

    public function testFullCycleIsUnaffectedByTheAnchorsTimeOfDay(): void
    {
        $contract = $this->createMonthlyContract(
            startDate: new \DateTimeImmutable('2026-07-25'),
            endDate: new \DateTimeImmutable('2026-11-25'),
            nextBillingDate: new \DateTimeImmutable('2026-10-25 15:15:48'),
        );
        $contract->applyIndividualMonthlyAmount(150_000, null, null, new \DateTimeImmutable('2026-07-01'));

        self::assertSame(150_000, $this->calculator->calculate($contract, new \DateTimeImmutable('2026-10-20')));
    }

    /**
     * Order 01a051d7 (1 900 Kč/month, 30.08 – 30.10): the anchor 30.09
     * 09:00:56 made 30.10 09:00:56 overshoot the 30.10 00:00 end date, so the
     * LAST full month was "prorated" to 29/30 days and requested as 1 837 Kč.
     * The customer wired the usual 1 900 Kč and 63 Kč was stranded as credit
     * on a contract that then ended.
     */
    public function testLastFullMonthEndingOnTheAnchorDayIsNotProrated(): void
    {
        $contract = $this->createMonthlyContract(
            startDate: new \DateTimeImmutable('2026-08-30'),
            endDate: new \DateTimeImmutable('2026-10-30'),
            nextBillingDate: new \DateTimeImmutable('2026-09-30 09:00:56'),
        );
        $contract->applyIndividualMonthlyAmount(190_000, null, null, new \DateTimeImmutable('2026-08-01'));

        self::assertSame(190_000, $this->calculator->calculate($contract, new \DateTimeImmutable('2026-09-23 05:00:00')));
    }

    /**
     * After a prolongation the live contract prices the 01.11 cycle as a full
     * month, but the request already sent to the customer stays authoritative.
     */
    public function testOpenRequestForTheCurrentCycleOverridesTheLiveRecalculation(): void
    {
        $contract = $this->createMonthlyContract(
            startDate: new \DateTimeImmutable('2026-07-25'),
            endDate: new \DateTimeImmutable('2027-05-31'),
            nextBillingDate: new \DateTimeImmutable('2026-11-01 15:15:48'),
        );
        $contract->applyIndividualMonthlyAmount(150_000, null, null, new \DateTimeImmutable('2026-07-01'));
        $now = new \DateTimeImmutable('2026-11-03 06:15:27');

        self::assertSame(150_000, $this->calculator->calculate($contract, $now));

        $request = new ManualPaymentRequest(
            id: Uuid::v7(),
            contract: $contract,
            periodStart: new \DateTimeImmutable('2026-11-01'),
            periodEnd: new \DateTimeImmutable('2026-11-25'),
            amount: 115_000,
            createdAt: new \DateTimeImmutable('2026-10-25 05:00:33'),
        );

        self::assertSame(115_000, $this->calculator->calculateForOpenCycle($contract, $now, $request));
        self::assertSame(115_000, $this->calculator->amountToRequest($contract, $now, $request));
    }

    public function testRequestForAnotherPeriodIsIgnored(): void
    {
        $contract = $this->createMonthlyContract(
            startDate: new \DateTimeImmutable('2026-07-25'),
            endDate: new \DateTimeImmutable('2027-05-31'),
            nextBillingDate: new \DateTimeImmutable('2026-11-01 15:15:48'),
        );
        $contract->applyIndividualMonthlyAmount(150_000, null, null, new \DateTimeImmutable('2026-07-01'));
        $stale = new ManualPaymentRequest(
            id: Uuid::v7(),
            contract: $contract,
            periodStart: new \DateTimeImmutable('2026-10-01'),
            periodEnd: new \DateTimeImmutable('2026-11-01'),
            amount: 99_900,
            createdAt: new \DateTimeImmutable('2026-09-24'),
        );

        self::assertSame(150_000, $this->calculator->calculateForOpenCycle($contract, new \DateTimeImmutable('2026-11-03'), $stale));
    }

    /**
     * The contract keeps its anchor day: a clamped 30.09 is followed by 31.10,
     * while an explicit re-anchoring (a prolonged prorated cycle resuming on
     * the 25th) becomes the new anchor.
     */
    public function testContractKeepsItsAnchorDayAcrossShortMonths(): void
    {
        $contract = $this->createMonthlyContract(
            startDate: new \DateTimeImmutable('2026-07-31'),
            endDate: new \DateTimeImmutable('2027-07-31'),
            nextBillingDate: new \DateTimeImmutable('2026-08-31 15:15:48'),
        );
        self::assertSame(31, $contract->billingAnchorDay);

        $september = $contract->advanceByCadence($contract->nextBillingDate ?? throw new \LogicException());
        self::assertSame('2026-09-30', $september->format('Y-m-d'));
        $contract->recordBillingCharge(new \DateTimeImmutable('2026-08-31'), $september, $september);
        self::assertSame(31, $contract->billingAnchorDay, 'A month-end clamp must not move the anchor.');

        self::assertSame('2026-10-31', $contract->advanceByCadence($september)->format('Y-m-d'));

        $contract->scheduleNextBilling(new \DateTimeImmutable('2026-11-25'), null);
        self::assertSame(25, $contract->billingAnchorDay, 'An explicit re-anchoring sets the new day.');
        self::assertSame('2026-12-25', $contract->advanceByCadence(new \DateTimeImmutable('2026-11-25'))->format('Y-m-d'));
    }

    private function createMonthlyContract(
        \DateTimeImmutable $startDate,
        \DateTimeImmutable $endDate,
        \DateTimeImmutable $nextBillingDate,
    ): Contract {
        $now = new \DateTimeImmutable('2026-01-01');
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', $now);
        $place = new Place(
            id: Uuid::v7(),
            name: 'Place',
            address: 'A',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: $now,
        );
        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Type',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 50000,
            defaultPricePerMonth: 180000,
            defaultPricePerMonthLongTerm: 150000,
            defaultPricePerYear: 1_800_000,
            createdAt: $now,
        );
        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $now,
        );
        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: 150_000,
            expiresAt: $now->modify('+7 days'),
            createdAt: $now,
        );

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: $now,
        );
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->applyPaymentFrequency(PaymentFrequency::MONTHLY);
        $contract->scheduleNextBilling($nextBillingDate, $nextBillingDate);

        return $contract;
    }
}
