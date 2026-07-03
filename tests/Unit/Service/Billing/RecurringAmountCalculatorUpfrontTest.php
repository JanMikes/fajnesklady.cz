<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Billing;

use App\Entity\Contract;
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
 * Spec 078 tranches: for an upfront (ONE_TIME) contract with a billing anchor
 * the expected charge is the CURRENT TRANCHE of the monthly walk — a full
 * 12-month block or the prorated remainder — never a flat monthly/yearly rate.
 */
class RecurringAmountCalculatorUpfrontTest extends TestCase
{
    private RecurringAmountCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new RecurringAmountCalculator(new PriceCalculator());
    }

    public function testFullTrancheIsTwelveTimesMonthlyRate(): void
    {
        // 26-month contract, anchor at month 12 → tranche 2 = 12 full months.
        $contract = $this->createUpfrontContract(
            startDate: new \DateTimeImmutable('2026-01-10'),
            endDate: new \DateTimeImmutable('2028-03-10'),
            nextBillingDate: new \DateTimeImmutable('2027-01-10'),
        );

        $amount = $this->calculator->calculate($contract, new \DateTimeImmutable('2027-01-03'));

        $this->assertSame(12 * 150000, $amount);
    }

    public function testFinalTrancheIsProratedRemainderOfTheMonthlyWalk(): void
    {
        // 15-month contract, anchor at month 12 → tranche 2 = 3 full months.
        $contract = $this->createUpfrontContract(
            startDate: new \DateTimeImmutable('2026-01-10'),
            endDate: new \DateTimeImmutable('2027-04-10'),
            nextBillingDate: new \DateTimeImmutable('2027-01-10'),
        );

        $amount = $this->calculator->calculate($contract, new \DateTimeImmutable('2027-01-03'));

        $this->assertSame(3 * 150000, $amount);
    }

    public function testFinalTrancheIncludesDailyProratedTail(): void
    {
        // 12 months + 10 days → tranche 2 = 10-day tail at the 30-day daily rate.
        $contract = $this->createUpfrontContract(
            startDate: new \DateTimeImmutable('2026-01-10'),
            endDate: new \DateTimeImmutable('2027-01-20'),
            nextBillingDate: new \DateTimeImmutable('2027-01-10'),
        );

        $amount = $this->calculator->calculate($contract, new \DateTimeImmutable('2027-01-03'));

        $this->assertSame(50000, $amount); // 10 × (150 000 / 30)
    }

    public function testTrancheAmountUsesLockedOrderRateNotLiveStoragePrice(): void
    {
        // The customer prepays exactly what was quoted: a storage price hike
        // during the rental must NOT shift the outstanding tranches. The locked
        // rate is recovered from firstPaymentPrice / 12 (first tranche is
        // always 12 full months).
        $contract = $this->createUpfrontContract(
            startDate: new \DateTimeImmutable('2026-01-10'),
            endDate: new \DateTimeImmutable('2028-03-10'),
            nextBillingDate: new \DateTimeImmutable('2027-01-10'),
        );

        // Admin edits the price list mid-rental: long-term rate 1 500 → 2 000 Kč.
        $contract->storage->updatePrices(null, null, 200000, null, new \DateTimeImmutable('2026-06-01'));

        $amount = $this->calculator->calculate($contract, new \DateTimeImmutable('2027-01-03'));

        $this->assertSame(12 * 150000, $amount, 'tranche must bill at the locked order rate');
    }

    private function createUpfrontContract(
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
            defaultPricePerYear: 150000 * 12,
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
            paymentFrequency: PaymentFrequency::ONE_TIME,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: 12 * 150000,
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
        $contract->applyBillingMode(BillingMode::ONE_TIME);
        $contract->applyPaymentFrequency(PaymentFrequency::ONE_TIME);
        $contract->recordBillingCharge($now, $nextBillingDate, $nextBillingDate);

        return $contract;
    }
}
