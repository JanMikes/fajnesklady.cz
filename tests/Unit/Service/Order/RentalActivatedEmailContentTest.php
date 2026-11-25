<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Order;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentFrequency;
use App\Enum\PaymentMethod;
use App\Service\Order\CustomerBillingSituation;
use App\Service\Order\RentalActivatedEmailContent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class RentalActivatedEmailContentTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2025-06-15 12:00:00');
    }

    public function testCardPaidRentalThatAlreadyStarted(): void
    {
        $contract = $this->createContract(start: '2025-06-15', end: '2026-06-15', method: PaymentMethod::GOPAY);
        $contract->setRecurringPayment('gp_parent', new \DateTimeImmutable('2025-07-15'), new \DateTimeImmutable('2025-07-15'));

        $content = RentalActivatedEmailContent::fromContract($contract, $this->now, 150_000);

        $this->assertSame(CustomerBillingSituation::GOPAY_FIRST_CHARGE, $content->situation);
        $this->assertSame('Pronájem zahájen', $content->title);
        $this->assertSame('Pronájem zahájen — platba přijata — Sklady Praha', $content->subject);
        $this->assertSame('Vaše platba byla úspěšně zpracována — pronájem skladu je aktivní', $content->headline);
        $this->assertStringContainsString('Děkujeme za Vaši platbu', $content->body);
        $this->assertStringContainsString('Nájemné je uhrazeno do 14.07.2025.', $content->body);
        $this->assertStringContainsString('podmínky opakovaných plateb', $content->body);
        $this->assertStringNotContainsString('QR kódem', $content->body, 'card cycles are charged automatically');
    }

    /**
     * Production incident 2026-11-24 (order 01a20ea1): a yearly rental paid by
     * bank transfer a week before its start was announced as "Pronájem
     * zahájen — předplaceno do 24.11.2027 … předplacen externě … Od 24.11.2027
     * činí nájemné …".
     */
    public function testYearlyBankTransferPaidBeforeStartCoveringWholeTerm(): void
    {
        $contract = $this->createContract(start: '2025-06-22', end: '2026-06-22', method: PaymentMethod::BANK_TRANSFER, frequency: PaymentFrequency::YEARLY);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->recordBillingCharge($this->now, null, new \DateTimeImmutable('2026-06-22'));

        $content = RentalActivatedEmailContent::fromContract($contract, $this->now);

        $this->assertSame(CustomerBillingSituation::GOPAY_FIRST_CHARGE, $content->situation, 'a customer who paid by bank transfer is not externally prepaid');
        $this->assertSame('Pronájem začne 22.06.2025', $content->title);
        $this->assertSame('Pronájem začne 22.06.2025 — platba přijata — Sklady Praha', $content->subject);
        $this->assertSame('Vaše platba byla úspěšně zpracována — pronájem skladu začne 22.06.2025', $content->headline);
        $this->assertStringContainsString('Nájemné je uhrazeno do konce smlouvy (22.06.2026) — žádné další platby Vás nečekají.', $content->body);
        $this->assertStringNotContainsString('extern', $content->body);
        $this->assertStringNotContainsString('extern', $content->subject);
        $this->assertStringNotContainsString('zahájen', $content->subject);
        $this->assertStringNotContainsString('opakovaných plateb', $content->body, 'no recurring card payment exists');
    }

    public function testMonthlyBankTransferNamesLastPaidDayAndNextDueDateWithoutRepeatingTheSameDay(): void
    {
        $contract = $this->createContract(start: '2025-06-22', end: '2025-12-22', method: PaymentMethod::BANK_TRANSFER);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->recordBillingCharge($this->now, new \DateTimeImmutable('2025-07-22'), new \DateTimeImmutable('2025-07-22'));

        $content = RentalActivatedEmailContent::fromContract($contract, $this->now, 175_000);

        $this->assertStringContainsString('Nájemné je uhrazeno do 21.07.2025.', $content->body);
        $this->assertStringContainsString('Další platba ve výši 1 750 Kč (vč. DPH) je splatná 22.07.2025 — před splatností Vám pošleme e-mail s platebními údaji a QR kódem', $content->body);
        $this->assertStringNotContainsString('do 22.07.2025', $content->body, 'the due day is not a paid day');
        $this->assertSame($contract->lastPaidDay()?->format('Y-m-d'), $content->paidThroughDate?->format('Y-m-d'));
    }

    public function testExternallyPrepaidContent(): void
    {
        $contract = $this->createContract(start: '2025-06-15', end: '2026-06-15', method: PaymentMethod::EXTERNAL);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $this->prepay($contract, new \DateTimeImmutable('2025-12-31'));

        $content = RentalActivatedEmailContent::fromContract($contract, $this->now);

        $this->assertSame(CustomerBillingSituation::EXTERNALLY_PREPAID, $content->situation);
        $this->assertSame('Pronájem zahájen — uhrazeno do 31.12.2025 — Sklady Praha', $content->subject);
        $this->assertSame('Pronájem byl zahájen', $content->headline);
        $this->assertStringContainsString('Nájemné máte uhrazené do 31.12.2025. Od 01.01.2026', $content->body);
        $this->assertStringContainsString('1 500 Kč / měsíc', $content->body);
        $this->assertStringContainsString('QR kódem', $content->body);
        $this->assertStringNotContainsString('extern', $content->body);
        $this->assertStringNotContainsString('Děkujeme za Vaši platbu', $content->body);
    }

    public function testExternallyPrepaidCoveringWholeTermPromisesNoFurtherPayments(): void
    {
        $contract = $this->createContract(start: '2025-06-15', end: '2026-06-15', method: PaymentMethod::EXTERNAL);
        $this->prepay($contract, $contract->endDate);

        $content = RentalActivatedEmailContent::fromContract($contract, $this->now);

        $this->assertNull($contract->nextBillingDate, 'prepayment to contract end must leave no billing anchor');
        $this->assertSame('Pronájem zahájen — uhrazeno do konce smlouvy — Sklady Praha', $content->subject);
        $this->assertStringContainsString('Nájemné máte uhrazené do konce smlouvy (15.06.2026) — žádné další platby Vás nečekají.', $content->body);
        $this->assertStringNotContainsString('QR kódem', $content->body);
    }

    public function testExternallyPrepaidRentalStartingLaterIsNotAnnouncedAsStarted(): void
    {
        $contract = $this->createContract(start: '2025-07-01', end: '2026-07-01', method: PaymentMethod::EXTERNAL);
        $this->prepay($contract, $contract->endDate);

        $content = RentalActivatedEmailContent::fromContract($contract, $this->now);

        $this->assertSame('Pronájem začne 01.07.2025', $content->title);
        $this->assertSame('Smlouva je podepsána — pronájem začne 01.07.2025', $content->headline);
    }

    public function testFreeContent(): void
    {
        $contract = $this->createContract(start: '2025-06-15', end: '2026-06-15', method: PaymentMethod::EXTERNAL);
        $contract->applyIndividualMonthlyAmount(0, null, null, $this->now);

        $content = RentalActivatedEmailContent::fromContract($contract, $this->now);

        $this->assertSame(CustomerBillingSituation::FREE, $content->situation);
        $this->assertSame('Pronájem zahájen — bezplatný pronájem — Sklady Praha', $content->subject);
        $this->assertStringContainsString('bezplatný pronájem', $content->headline);
        $this->assertStringNotContainsString('Děkujeme za Vaši platbu', $content->body);
    }

    /**
     * What OrderService::completeOrder() does for an admin-recorded prepayment:
     * the marker lives on the ORDER, the contract mirrors it.
     */
    private function prepay(Contract $contract, \DateTimeImmutable $paidThrough): void
    {
        $contract->order->setOnboardingBillingTerms(null, $paidThrough);
        $contract->markExternallyPrepaid($paidThrough);
    }

    private function createContract(
        string $start,
        string $end,
        PaymentMethod $method,
        PaymentFrequency $frequency = PaymentFrequency::MONTHLY,
    ): Contract {
        $createdAt = new \DateTimeImmutable('2025-06-01');
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', $createdAt);
        $owner = new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', $createdAt);

        $place = new Place(Uuid::v7(), 'Sklady Praha', 'Test Address', 'Praha', '110 00', null, $createdAt);

        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $place,
            name: 'Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 175_000,
            defaultPricePerMonthLongTerm: 150_000,
            defaultPricePerYear: 1_620_000,
            createdAt: $createdAt,
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: $createdAt,
            owner: $owner,
        );

        $order = new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: $frequency,
            startDate: new \DateTimeImmutable($start),
            endDate: new \DateTimeImmutable($end),
            firstPaymentPrice: 150_000,
            expiresAt: new \DateTimeImmutable('2025-06-20'),
            createdAt: $createdAt,
        );
        $order->setPaymentMethod($method);

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: $order->startDate,
            endDate: new \DateTimeImmutable($end),
            createdAt: $this->now,
        );
        $contract->applyPaymentFrequency($frequency);

        return $contract;
    }
}
