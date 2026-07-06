<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Order;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\Order\CustomerBillingSituation;
use App\Service\Order\RentalActivatedEmailContent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class RentalActivatedEmailContentTest extends TestCase
{
    public function testGoPayContent(): void
    {
        $contract = $this->createContract(placeName: 'Sklady Praha');

        $content = RentalActivatedEmailContent::fromContract($contract);

        $this->assertSame(CustomerBillingSituation::GOPAY_FIRST_CHARGE, $content->situation);
        $this->assertSame('Pronájem zahájen — platba zpracována — Sklady Praha', $content->subject);
        $this->assertSame('Vaše platba byla úspěšně zpracována — pronájem skladu je aktivní', $content->headline);
        $this->assertStringContainsString('Děkujeme za Vaši platbu', $content->body);
    }

    public function testExternallyPrepaidContent(): void
    {
        $contract = $this->createContract(placeName: 'Sklady Praha');
        $contract->markExternallyPrepaid(new \DateTimeImmutable('2026-12-31'));

        $content = RentalActivatedEmailContent::fromContract($contract);

        $this->assertSame(CustomerBillingSituation::EXTERNALLY_PREPAID, $content->situation);
        $this->assertSame('Pronájem zahájen — předplaceno do 31.12.2026 — Sklady Praha', $content->subject);
        $this->assertSame('Pronájem byl zahájen', $content->headline);
        $this->assertStringContainsString('předplacen externě do 31.12.2026', $content->body);
        $this->assertStringContainsString('Od 01.01.2027', $content->body);
        $this->assertStringContainsString('1 500 Kč / měsíc', $content->body);
        $this->assertStringContainsString('QR kódem', $content->body);
        $this->assertStringNotContainsString('kontaktujeme', $content->body);
        $this->assertStringNotContainsString('Děkujeme za Vaši platbu', $content->body);
        $this->assertNotNull($content->paidThroughDate);
    }

    public function testExternallyPrepaidCoveringWholeTermPromisesNoFurtherPayments(): void
    {
        $contract = $this->createContract(placeName: 'Sklady Praha');
        $contract->markExternallyPrepaid($contract->endDate);

        $content = RentalActivatedEmailContent::fromContract($contract);

        $this->assertNull($contract->nextBillingDate, 'prepayment to contract end must leave no billing anchor');
        $this->assertStringContainsString('do konce smlouvy', $content->body);
        $this->assertStringContainsString('žádné další platby Vás nečekají', $content->body);
        $this->assertStringNotContainsString('QR kódem', $content->body);
    }

    public function testFreeContent(): void
    {
        $contract = $this->createContract(placeName: 'Sklady Praha');
        $contract->applyIndividualMonthlyAmount(0, null, null, new \DateTimeImmutable('2025-06-15 12:00:00'));

        $content = RentalActivatedEmailContent::fromContract($contract);

        $this->assertSame(CustomerBillingSituation::FREE, $content->situation);
        $this->assertSame('Pronájem zahájen — bezplatný pronájem — Sklady Praha', $content->subject);
        $this->assertStringContainsString('bezplatný pronájem', $content->headline);
        $this->assertStringNotContainsString('Děkujeme za Vaši platbu', $content->body);
    }

    private function createContract(string $placeName): Contract
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
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $startDate->modify('+12 months'),
            firstPaymentPrice: 80_000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
        $order->setPaymentMethod(PaymentMethod::GOPAY);

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: $order->startDate,
            endDate: $order->startDate->modify('+12 months'),
            createdAt: new \DateTimeImmutable('2025-06-15 12:00:00'),
        );
    }
}
