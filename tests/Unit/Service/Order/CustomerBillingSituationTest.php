<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Order;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentMethod;
use App\Enum\RentalType;
use App\Service\Order\CustomerBillingSituation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class CustomerBillingSituationTest extends TestCase
{
    public function testFromOrderReturnsGoPayWhenNoOverrides(): void
    {
        $order = $this->createOrder();

        $this->assertSame(
            CustomerBillingSituation::GOPAY_FIRST_CHARGE,
            CustomerBillingSituation::fromOrder($order),
        );
    }

    public function testFromOrderReturnsExternallyPrepaidWhenPaidThroughDateSet(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingBillingTerms(80_000, new \DateTimeImmutable('2026-12-31'));

        $this->assertSame(
            CustomerBillingSituation::EXTERNALLY_PREPAID,
            CustomerBillingSituation::fromOrder($order),
        );
    }

    public function testFromOrderReturnsFreeWhenIndividualMonthlyIsZero(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingBillingTerms(0, null);

        $this->assertSame(
            CustomerBillingSituation::FREE,
            CustomerBillingSituation::fromOrder($order),
        );
    }

    public function testFromOrderPrefersFreeOverPrepaid(): void
    {
        $order = $this->createOrder();
        $order->setOnboardingBillingTerms(0, new \DateTimeImmutable('2026-12-31'));

        $this->assertSame(
            CustomerBillingSituation::FREE,
            CustomerBillingSituation::fromOrder($order),
        );
    }

    public function testFromContractReturnsGoPayByDefault(): void
    {
        $contract = $this->createContract();

        $this->assertSame(
            CustomerBillingSituation::GOPAY_FIRST_CHARGE,
            CustomerBillingSituation::fromContract($contract),
        );
    }

    public function testFromContractReturnsExternallyPrepaidWhenMarkedAndNoGoPayToken(): void
    {
        $contract = $this->createContract();
        $contract->markExternallyPrepaid(new \DateTimeImmutable('2026-12-31'));

        $this->assertSame(
            CustomerBillingSituation::EXTERNALLY_PREPAID,
            CustomerBillingSituation::fromContract($contract),
        );
    }

    public function testFromContractReturnsFreeWhenIndividualMonthlyIsZero(): void
    {
        $contract = $this->createContract();
        $contract->applyIndividualMonthlyAmount(0, null, null, new \DateTimeImmutable('2025-06-15 12:00:00'));

        $this->assertSame(
            CustomerBillingSituation::FREE,
            CustomerBillingSituation::fromContract($contract),
        );
    }

    public function testFromContractPrefersFreeOverPrepaid(): void
    {
        $contract = $this->createContract();
        $contract->markExternallyPrepaid(new \DateTimeImmutable('2026-12-31'));
        $contract->applyIndividualMonthlyAmount(0, null, null, new \DateTimeImmutable('2025-06-15 12:00:00'));

        $this->assertSame(
            CustomerBillingSituation::FREE,
            CustomerBillingSituation::fromContract($contract),
        );
    }

    public function testFromContractReturnsGoPayWhenPrepaidButTokenSet(): void
    {
        $contract = $this->createContract();
        $contract->setRecurringPayment('PARENT-1', null, new \DateTimeImmutable('2026-12-31'));

        $this->assertSame(
            CustomerBillingSituation::GOPAY_FIRST_CHARGE,
            CustomerBillingSituation::fromContract($contract),
        );
    }

    private function createOrder(): Order
    {
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', new \DateTimeImmutable());
        $owner = new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', new \DateTimeImmutable());

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
            name: 'Small Box',
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
            owner: $owner,
        );

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::UNLIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('+1 day'),
            endDate: null,
            firstPaymentPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
        $order->setPaymentMethod(PaymentMethod::GOPAY);

        return $order;
    }

    private function createContract(): Contract
    {
        $order = $this->createOrder();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            rentalType: RentalType::UNLIMITED,
            startDate: $order->startDate,
            endDate: null,
            createdAt: $now,
        );
    }
}
