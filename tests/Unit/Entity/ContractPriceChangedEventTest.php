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
use App\Enum\RentalType;
use App\Event\ContractPriceChanged;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ContractPriceChangedEventTest extends TestCase
{
    public function testRecordsEventOnFirstApply(): void
    {
        $contract = $this->createContract();
        $admin = $this->createAdmin();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $contract->applyIndividualMonthlyAmount(80_000, $admin, 'Sleva', $now);

        $events = $contract->popEvents();
        $this->assertCount(1, $events);

        $event = $events[0];
        $this->assertInstanceOf(ContractPriceChanged::class, $event);
        $this->assertNull($event->previousAmount);
        $this->assertSame(80_000, $event->newAmount);
        $this->assertSame($admin, $event->changedBy);
        $this->assertSame('Sleva', $event->reason);
        $this->assertSame($now, $event->occurredOn);
        $this->assertSame($contract->id, $event->contractId);
    }

    public function testSecondApplyCarriesPreviousAmount(): void
    {
        $contract = $this->createContract();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $contract->applyIndividualMonthlyAmount(80_000, null, null, $now);
        $contract->popEvents();

        $contract->applyIndividualMonthlyAmount(95_000, null, 'Zvýšení', $now);

        $event = $this->popOnePriceChange($contract);
        $this->assertSame(80_000, $event->previousAmount);
        $this->assertSame(95_000, $event->newAmount);
    }

    public function testApplyNullClearsOverrideAndCarriesPreviousAmount(): void
    {
        $contract = $this->createContract();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $contract->applyIndividualMonthlyAmount(80_000, null, null, $now);
        $contract->popEvents();

        $contract->applyIndividualMonthlyAmount(null, null, 'Zpět na standard', $now);

        $event = $this->popOnePriceChange($contract);
        $this->assertSame(80_000, $event->previousAmount);
        $this->assertNull($event->newAmount);
    }

    public function testFreeContractAmountRecordsZero(): void
    {
        $contract = $this->createContract();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $contract->applyIndividualMonthlyAmount(0, null, null, $now);

        $event = $this->popOnePriceChange($contract);
        $this->assertNull($event->previousAmount);
        $this->assertSame(0, $event->newAmount);
    }

    public function testReaffirmingSameAmountStillRecords(): void
    {
        $contract = $this->createContract();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        $contract->applyIndividualMonthlyAmount(80_000, null, null, $now);
        $contract->popEvents();

        $contract->applyIndividualMonthlyAmount(80_000, null, 'Re-affirmation', $now);

        $event = $this->popOnePriceChange($contract);
        $this->assertSame(80_000, $event->previousAmount);
        $this->assertSame(80_000, $event->newAmount);
        $this->assertSame('Re-affirmation', $event->reason);
    }

    public function testNegativeAmountThrowsAndRecordsNoEvent(): void
    {
        $contract = $this->createContract();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        try {
            $contract->applyIndividualMonthlyAmount(-1, null, null, $now);
            $this->fail('InvalidArgumentException was not thrown.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertSame([], $contract->popEvents());
    }

    public function testOverCapAmountThrowsAndRecordsNoEvent(): void
    {
        $contract = $this->createContract();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        try {
            $contract->applyIndividualMonthlyAmount(1_600_000, null, null, $now);
            $this->fail('DomainException was not thrown.');
        } catch (\DomainException) {
            // expected
        }

        $this->assertSame([], $contract->popEvents());
    }

    private function createContract(): Contract
    {
        $user = new User(Uuid::v7(), 'tenant@test.com', 'password', 'Tenant', 'User', new \DateTimeImmutable());
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );
        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Small',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: new \DateTimeImmutable(),
        );
        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );
        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: new \DateTimeImmutable('+1 day'),
            endDate: new \DateTimeImmutable('+30 days'),
            firstPaymentPrice: 50000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            startDate: new \DateTimeImmutable(),
            endDate: new \DateTimeImmutable('+30 days'),
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createAdmin(): User
    {
        return new User(Uuid::v7(), 'admin@test.com', 'pwd', 'Admin', 'User', new \DateTimeImmutable());
    }

    private function popOnePriceChange(Contract $contract): ContractPriceChanged
    {
        $events = $contract->popEvents();
        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(ContractPriceChanged::class, $event);

        return $event;
    }
}
