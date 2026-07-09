<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 086 — payment-deadline extension (paymentGraceUntil) + external payment.
 */
class ContractPaymentGraceTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2025-06-15 12:00:00');
    }

    public function testIsInPaymentGraceIsFalseWithoutExtension(): void
    {
        $contract = $this->createContract();

        self::assertFalse($contract->isInPaymentGrace($this->now));
        self::assertNull($contract->paymentGraceUntil);
    }

    public function testExtendSetsGraceAndIsInGraceThroughTheDateInclusive(): void
    {
        $contract = $this->createContract();
        $contract->scheduleNextBilling($this->now->modify('-2 days'), null);
        $newDeadline = $this->now->modify('+10 days');

        $contract->extendPaymentDeadline($newDeadline, $this->now);

        self::assertEquals($newDeadline, $contract->paymentGraceUntil);
        self::assertTrue($contract->isInPaymentGrace($this->now), 'In grace before the extended date.');
        self::assertTrue($contract->isInPaymentGrace($newDeadline), 'In grace on the extended date itself.');
        self::assertFalse($contract->isInPaymentGrace($newDeadline->modify('+1 day')), 'Out of grace the day after.');
    }

    public function testEffectiveDunningAnchorPrefersGraceOverBillingDate(): void
    {
        $contract = $this->createContract();
        $anchor = $this->now->modify('-2 days');
        $contract->scheduleNextBilling($anchor, null);

        self::assertEquals($anchor, $contract->effectiveDunningAnchor(), 'No grace: raw billing anchor.');

        $newDeadline = $this->now->modify('+10 days');
        $contract->extendPaymentDeadline($newDeadline, $this->now);

        self::assertEquals($newDeadline, $contract->effectiveDunningAnchor(), 'Grace re-anchors dunning + termination.');
    }

    public function testExtendRejectsDeadlineInThePast(): void
    {
        $contract = $this->createContract();
        $contract->scheduleNextBilling($this->now->modify('-5 days'), null);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('New deadline must be in the future.');

        $contract->extendPaymentDeadline($this->now->modify('-1 day'), $this->now);
    }

    public function testExtendRejectsDeadlineNotAfterCurrentAnchor(): void
    {
        $contract = $this->createContract();
        $anchor = $this->now->modify('+3 days');
        $contract->scheduleNextBilling($anchor, null);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('New deadline must be after the current due date.');

        $contract->extendPaymentDeadline($anchor, $this->now);
    }

    public function testExtendRejectsWhenNothingIsDue(): void
    {
        $contract = $this->createContract();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Nothing is due');

        $contract->extendPaymentDeadline($this->now->modify('+10 days'), $this->now);
    }

    public function testExtendRejectsTerminatedContract(): void
    {
        $contract = $this->createContract();
        $contract->scheduleNextBilling($this->now->modify('-2 days'), null);
        $contract->terminate($this->now);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot extend a terminated contract.');

        $contract->extendPaymentDeadline($this->now->modify('+10 days'), $this->now);
    }

    public function testRecordExternalPaymentAdvancesAnchorAndClearsDunningFlags(): void
    {
        $contract = $this->createContract();
        $contract->scheduleNextBilling($this->now->modify('-2 days'), null);
        $contract->recordFailedBillingAttempt($this->now->modify('-2 days'));
        $contract->recordPaymentDemandSent($this->now->modify('-2 days'));
        $contract->extendPaymentDeadline($this->now->modify('+10 days'), $this->now);

        $paidThrough = $this->now->modify('+1 month');
        $contract->recordExternalPayment($paidThrough, $this->now);

        self::assertEquals($paidThrough, $contract->paidThroughDate);
        self::assertEquals($paidThrough->modify('+1 day'), $contract->nextBillingDate, 'Billing resumes the day after.');
        self::assertSame(0, $contract->failedBillingAttempts, 'A payment clears the failed-attempt counter.');
        self::assertNull($contract->paymentDemandSentAt);
        self::assertNull($contract->paymentGraceUntil, 'A payment makes the extension moot.');
    }

    public function testRecordExternalPaymentCoveringWholeTermLeavesNoAnchor(): void
    {
        $contract = $this->createContract();
        $contract->scheduleNextBilling($this->now, null);

        $contract->recordExternalPayment($contract->endDate->modify('+1 day'), $this->now);

        self::assertNull($contract->nextBillingDate, 'Prepaid past the end: nothing left to bill.');
    }

    public function testRecordBillingChargeClearsActiveGrace(): void
    {
        $contract = $this->createContract();
        $contract->scheduleNextBilling($this->now->modify('-2 days'), null);
        $contract->extendPaymentDeadline($this->now->modify('+10 days'), $this->now);

        $next = $this->now->modify('+1 month');
        $contract->recordBillingCharge($this->now, $next, $next);

        self::assertNull($contract->paymentGraceUntil, 'A real charge during grace clears the extension.');
    }

    private function createContract(): Contract
    {
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', $this->now);
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
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: $this->now,
        );
        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $this->now,
            owner: null,
        );
        $startDate = $this->now->modify('-1 month');
        $endDate = $this->now->modify('+6 months');
        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $endDate,
            firstPaymentPrice: 35000,
            expiresAt: $this->now->modify('+7 days'),
            createdAt: $this->now,
        );
        $order->popEvents();

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: $this->now,
        );
    }
}
