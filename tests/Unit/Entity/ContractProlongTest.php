<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Event\ContractProlonged;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Spec 077 — Contract::prolong() guards + billing re-seed matrix.
 */
class ContractProlongTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2025-06-15 12:00:00');
    }

    public function testProlongMovesEndDateAndRecordsEvent(): void
    {
        $contract = $this->createContract();
        $previousEnd = $contract->endDate;
        $newEnd = $previousEnd->modify('+6 months');
        $actor = $this->createUser();

        $contract->prolong($newEnd, $actor, $this->now);

        self::assertEquals($newEnd, $contract->endDate);

        $events = $contract->popEvents();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertInstanceOf(ContractProlonged::class, $event);
        self::assertEquals($previousEnd, $event->previousEndDate);
        self::assertEquals($newEnd, $event->newEndDate);
        self::assertSame($actor, $event->prolongedBy);
    }

    public function testProlongRejectsTerminatedContract(): void
    {
        $contract = $this->createContract();
        $contract->terminate($this->now);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot prolong a terminated contract.');

        $contract->prolong($contract->endDate->modify('+1 month'), null, $this->now);
    }

    public function testProlongRejectsPendingTermination(): void
    {
        $contract = $this->createContract();
        $contract->requestTermination($this->now, $this->now->modify('+30 days'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot prolong a contract with a pending termination.');

        $contract->prolong($contract->endDate->modify('+1 month'), null, $this->now);
    }

    public function testProlongRejectsEndDateNotAfterCurrent(): void
    {
        $contract = $this->createContract();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('New end date must be after the current end date.');

        $contract->prolong($contract->endDate, null, $this->now);
    }

    public function testProlongMidTermKeepsRunningCadence(): void
    {
        $contract = $this->createContract();
        $nextBilling = $this->now->modify('+20 days');
        $contract->setRecurringPayment('gp_parent', $nextBilling, $nextBilling);

        $contract->prolong($contract->endDate->modify('+6 months'), null, $this->now);

        self::assertEquals($nextBilling, $contract->nextBillingDate, 'Running cadence must not shift.');
    }

    public function testProlongAfterFinalChargeResumesBillingFromPaidThrough(): void
    {
        $contract = $this->createContract();
        $previousEnd = $contract->endDate;
        // Final prorated cycle already ran: schedule closed, paid through the end.
        $contract->setRecurringPayment('gp_parent', null, $previousEnd);

        $contract->prolong($previousEnd->modify('+3 months'), null, $this->now);

        self::assertEquals($previousEnd, $contract->nextBillingDate, 'Billing must resume where the paid period ends.');
    }

    public function testProlongFullyPrepaidContractResumesBillingDayAfterPaidThrough(): void
    {
        // Spec 085: external prepayment covering the whole term leaves no
        // billing anchor (markExternallyPrepaid caps at endDate). Prolonging
        // must resume the day AFTER the inclusive paid-through day — not on it.
        $contract = $this->createContract();
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $previousEnd = $contract->endDate;
        $contract->markExternallyPrepaid($previousEnd);
        self::assertNull($contract->nextBillingDate);

        $contract->prolong($previousEnd->modify('+3 months'), null, $this->now);

        self::assertEquals($previousEnd->modify('+1 day'), $contract->nextBillingDate, 'Customer already paid through the previous end date inclusive.');
    }

    public function testProlongOneTimeContractDoesNotReseedBilling(): void
    {
        $contract = $this->createContract();
        $contract->applyBillingMode(BillingMode::ONE_TIME);

        $contract->prolong($contract->endDate->modify('+1 month'), null, $this->now);

        self::assertNull($contract->nextBillingDate, 'ONE_TIME conversion is the handler\'s job, not prolong().');
    }

    public function testProlongFreeContractDoesNotReseedBilling(): void
    {
        $contract = $this->createContract();
        $contract->applyIndividualMonthlyAmount(0, null, null, $this->now);
        $contract->popEvents();

        $contract->prolong($contract->endDate->modify('+1 month'), null, $this->now);

        self::assertNull($contract->nextBillingDate, 'Free contracts have nothing to bill.');
    }

    private function createUser(string $email = 'user@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'password', 'Test', 'User', $this->now);
    }

    private function createContract(): Contract
    {
        $user = $this->createUser();
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
        $startDate = $this->now->modify('+1 day');
        $endDate = $startDate->modify('+6 months');
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
