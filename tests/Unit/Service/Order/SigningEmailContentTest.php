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
use App\Service\Order\SigningEmailContent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class SigningEmailContentTest extends TestCase
{
    public function testGoPayContent(): void
    {
        $order = $this->createOrder(placeName: 'Sklady Praha');

        $content = SigningEmailContent::fromOrder($order);

        $this->assertSame(CustomerBillingSituation::GOPAY_FIRST_CHARGE, $content->situation);
        $this->assertSame('Podepište smlouvu a zaplaťte — pronájem skladu v Sklady Praha', $content->subject);
        $this->assertSame('Podpis smlouvy a platba', $content->headline);
        $this->assertStringContainsString('GoPay', $content->nextStepLine);
        $this->assertSame('Podepsat a zaplatit', $content->buttonLabel);
        $this->assertSame(80_000, $content->monthlyPriceInHaler);
        $this->assertSame('měsíc', $content->cadenceLabel);
    }

    public function testYearlyOrderUsesYearlyCadence(): void
    {
        // Bank-transfer onboarding with YEARLY frequency lands in the same
        // catch-all situation — firstPaymentPrice is then a PER-YEAR figure
        // and the price row must never label it "/ měsíc".
        $order = $this->createOrder(placeName: 'Sklady Praha', paymentFrequency: PaymentFrequency::YEARLY);
        $order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);

        $content = SigningEmailContent::fromOrder($order);

        $this->assertSame(CustomerBillingSituation::GOPAY_FIRST_CHARGE, $content->situation);
        $this->assertSame(80_000, $content->monthlyPriceInHaler);
        $this->assertSame('rok', $content->cadenceLabel);
    }

    public function testExternallyPrepaidContent(): void
    {
        $order = $this->createOrder(placeName: 'Sklady Praha');
        $order->setOnboardingBillingTerms(80_000, new \DateTimeImmutable('2026-12-31'));

        $content = SigningEmailContent::fromOrder($order);

        $this->assertSame(CustomerBillingSituation::EXTERNALLY_PREPAID, $content->situation);
        $this->assertSame('Podepište smlouvu — předplaceno do 31.12.2026', $content->subject);
        $this->assertStringContainsString('předplacen externě do 31.12.2026', $content->nextStepLine);
        $this->assertSame('Podepsat smlouvu', $content->buttonLabel);
        $this->assertSame(80_000, $content->monthlyPriceInHaler);
        $this->assertNotNull($content->paidThroughDate);
        $this->assertNotNull($content->billingResumesOn);
        $this->assertSame('2027-01-01', $content->billingResumesOn->format('Y-m-d'));
        $this->assertNotNull($content->futureBillingLine);
        $this->assertStringContainsString('Od 01.01.2027', $content->futureBillingLine);
        $this->assertStringContainsString('800 Kč / měsíc', $content->futureBillingLine);
        $this->assertStringContainsString('7 dní předem', $content->futureBillingLine);
        $this->assertStringContainsString('QR kódem', $content->futureBillingLine);
        $this->assertSame('měsíc', $content->cadenceLabel);
    }

    public function testExternallyPrepaidCoveringWholeTermPromisesNoFurtherPayments(): void
    {
        $order = $this->createOrder(placeName: 'Sklady Praha');
        \assert(null !== $order->endDate);
        $order->setOnboardingBillingTerms(80_000, $order->endDate);

        $content = SigningEmailContent::fromOrder($order);

        $this->assertSame(CustomerBillingSituation::EXTERNALLY_PREPAID, $content->situation);
        $this->assertStringContainsString('do konce smlouvy', $content->nextStepLine);
        $this->assertStringContainsString('žádné platby nečekají', $content->nextStepLine);
        $this->assertNull($content->futureBillingLine);
        $this->assertNull($content->billingResumesOn);
    }

    public function testFreeContent(): void
    {
        $order = $this->createOrder(placeName: 'Sklady Praha');
        $order->setOnboardingBillingTerms(0, null);

        $content = SigningEmailContent::fromOrder($order);

        $this->assertSame(CustomerBillingSituation::FREE, $content->situation);
        $this->assertSame('Podepište smlouvu — bezplatný pronájem', $content->subject);
        $this->assertSame('Bezplatný pronájem — po podpisu nemusíte nic platit.', $content->nextStepLine);
        $this->assertSame('Podepsat smlouvu', $content->buttonLabel);
        $this->assertSame(0, $content->monthlyPriceInHaler);
    }

    public function testAwaitingPaymentChoiceContentIsNeutral(): void
    {
        // Spec 088: a deferred onboarding has no locked method/price yet.
        $order = $this->createOrder(placeName: 'Sklady Praha');
        $order->markCustomerChoosesPayment();
        (new \ReflectionClass($order))->getProperty('paymentMethod')->setValue($order, null);
        $this->assertTrue($order->isAwaitingPaymentChoice());

        $content = SigningEmailContent::fromOrder($order);

        $this->assertTrue($content->awaitingChoice);
        $this->assertSame('Vyberte způsob platby a podepište smlouvu — pronájem skladu v Sklady Praha', $content->subject);
        $this->assertSame('Vybrat platbu a podepsat', $content->buttonLabel);
        $this->assertSame(0, $content->monthlyPriceInHaler);
    }

    private function createOrder(string $placeName, PaymentFrequency $paymentFrequency = PaymentFrequency::MONTHLY): Order
    {
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', new \DateTimeImmutable());
        $owner = new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', new \DateTimeImmutable());

        $place = new Place(Uuid::v7(), $placeName, 'Test Address', 'Praha', '110 00', null, new \DateTimeImmutable());

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
            paymentFrequency: $paymentFrequency,
            startDate: $startDate,
            endDate: $startDate->modify('+12 months'),
            firstPaymentPrice: 80_000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
        $order->setPaymentMethod(PaymentMethod::GOPAY);

        return $order;
    }
}
