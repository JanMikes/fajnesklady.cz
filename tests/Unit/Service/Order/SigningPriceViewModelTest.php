<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Order;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\Order\CustomerBillingSituation;
use App\Service\Order\SigningPriceViewModel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SigningPriceViewModelTest extends TestCase
{
    public function testReadsLockedInMonthlyFromOrderForGoPay(): void
    {
        $order = $this->createOrder(firstPaymentPrice: 80_000);

        $vm = SigningPriceViewModel::fromOrder($order);

        $this->assertSame(CustomerBillingSituation::GOPAY_FIRST_CHARGE, $vm->situation);
        $this->assertSame(80_000, $vm->monthlyPriceInHaler);
        $this->assertTrue($vm->isRecurring);
        $this->assertNull($vm->paidThroughDate);
        $this->assertNull($vm->billingResumesOn);
        $this->assertFalse($vm->prepaidCoversWholeTerm);
    }

    public function testReturnsExternallyPrepaidWhenPaidThroughDateSet(): void
    {
        $order = $this->createOrder(firstPaymentPrice: 80_000);
        $order->setOnboardingBillingTerms(80_000, new \DateTimeImmutable('2026-12-31'));

        $vm = SigningPriceViewModel::fromOrder($order);

        $this->assertSame(CustomerBillingSituation::EXTERNALLY_PREPAID, $vm->situation);
        $this->assertSame(80_000, $vm->monthlyPriceInHaler);
        $this->assertNotNull($vm->paidThroughDate);
        $this->assertSame('2026-12-31', $vm->paidThroughDate->format('Y-m-d'));
        $this->assertNotNull($vm->billingResumesOn);
        $this->assertSame('2027-01-01', $vm->billingResumesOn->format('Y-m-d'));
        $this->assertFalse($vm->prepaidCoversWholeTerm);
        $this->assertSame(80_000, $vm->recurringAmountInHaler);
        $this->assertSame('měsíc', $vm->cadenceLabel);
        $this->assertSame(7, $vm->reminderDaysBefore);
    }

    public function testPrepaidCoveringWholeTermHasNoResumeStory(): void
    {
        $order = $this->createOrder(firstPaymentPrice: 80_000);
        \assert(null !== $order->endDate);
        $order->setOnboardingBillingTerms(80_000, $order->endDate);

        $vm = SigningPriceViewModel::fromOrder($order);

        $this->assertSame(CustomerBillingSituation::EXTERNALLY_PREPAID, $vm->situation);
        $this->assertTrue($vm->prepaidCoversWholeTerm);
    }

    public function testReturnsFreeWhenIndividualMonthlyIsZero(): void
    {
        $order = $this->createOrder(firstPaymentPrice: 0);
        $order->setOnboardingBillingTerms(0, null);

        $vm = SigningPriceViewModel::fromOrder($order);

        $this->assertSame(CustomerBillingSituation::FREE, $vm->situation);
    }

    private function createOrder(int $firstPaymentPrice): Order
    {
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', new \DateTimeImmutable());
        $owner = new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', new \DateTimeImmutable());

        $place = new Place(Uuid::v7(), 'Test Place', 'Test Address', 'Praha', '110 00', null, new \DateTimeImmutable());

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 150_000,
            defaultPricePerMonthLongTerm: 150_000,
            defaultPricePerYear: 150_000 * 12,
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

        $startDate = new \DateTimeImmutable('+1 day');

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $startDate->modify('+12 months'),
            firstPaymentPrice: $firstPaymentPrice,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
        $order->setPaymentMethod(PaymentMethod::GOPAY);

        return $order;
    }
}
