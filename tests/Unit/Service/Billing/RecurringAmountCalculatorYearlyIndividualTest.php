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
 * Admin-onboarding individual pricing on YEARLY contracts: the stored
 * individual amount is a per-YEAR figure and must drive every recurring
 * yearly charge (full cycles and the prorated last cycle) instead of the
 * storage's yearly rate.
 */
class RecurringAmountCalculatorYearlyIndividualTest extends TestCase
{
    private RecurringAmountCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new RecurringAmountCalculator(new PriceCalculator());
    }

    public function testFullYearlyCycleBillsIndividualYearlyAmount(): void
    {
        // 3-year contract, anchor at year 1 → next full year fits: charge the
        // individual yearly figure, not the storage yearly rate (1 800 000).
        $contract = $this->createYearlyContract(
            startDate: new \DateTimeImmutable('2026-01-10'),
            endDate: new \DateTimeImmutable('2029-01-10'),
            nextBillingDate: new \DateTimeImmutable('2027-01-10'),
        );
        $contract->applyIndividualMonthlyAmount(2_400_000, null, null, new \DateTimeImmutable('2026-01-01'));

        $amount = $this->calculator->calculate($contract, new \DateTimeImmutable('2027-01-03'));

        $this->assertSame(2_400_000, $amount);
    }

    public function testLastCycleProratesIndividualYearlyAmount(): void
    {
        // 18-month contract, anchor at year 1 → 181 remaining days prorated
        // off the individual yearly figure over 365 days, rounded up to CZK.
        $contract = $this->createYearlyContract(
            startDate: new \DateTimeImmutable('2026-01-10'),
            endDate: new \DateTimeImmutable('2027-07-10'),
            nextBillingDate: new \DateTimeImmutable('2027-01-10'),
        );
        $contract->applyIndividualMonthlyAmount(2_400_000, null, null, new \DateTimeImmutable('2026-01-01'));

        $amount = $this->calculator->calculate($contract, new \DateTimeImmutable('2027-01-03'));

        $this->assertSame(1_190_200, $amount); // ceil(181 × 2 400 000 / 365 / 100) × 100
    }

    public function testYearlyWithoutIndividualAmountStillBillsStorageYearlyRate(): void
    {
        $contract = $this->createYearlyContract(
            startDate: new \DateTimeImmutable('2026-01-10'),
            endDate: new \DateTimeImmutable('2029-01-10'),
            nextBillingDate: new \DateTimeImmutable('2027-01-10'),
        );

        $amount = $this->calculator->calculate($contract, new \DateTimeImmutable('2027-01-03'));

        $this->assertSame(1_800_000, $amount);
    }

    private function createYearlyContract(
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
            paymentFrequency: PaymentFrequency::YEARLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: 1_800_000,
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
        $contract->applyPaymentFrequency(PaymentFrequency::YEARLY);
        $contract->scheduleNextBilling($nextBillingDate, $nextBillingDate);

        return $contract;
    }
}
