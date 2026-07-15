<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Order;

use App\Entity\Contract;
use App\Entity\ManualPaymentRequest;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\BillingMode;
use App\Enum\PaymentMethod;
use App\Enum\TerminationReason;
use App\Service\Order\AdminOrderStage;
use App\Service\Order\AdminOrderStageFactory;
use App\Value\OverdueContractView;
use App\Value\OverdueSeverity;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class AdminOrderStageFactoryTest extends TestCase
{
    private AdminOrderStageFactory $factory;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->factory = new AdminOrderStageFactory();
        $this->now = new \DateTimeImmutable('2025-06-15 12:00:00');
    }

    public function testOnboardingOrderWaitingForSignature(): void
    {
        $order = $this->createOrder();
        $order->markAsAdminCreated();
        $order->setSigningToken('token');

        $stage = $this->factory->build($order, null, null, null, 0, $this->now);

        $this->assertSame('Čeká na podpis zákazníka', $stage->label);
        $this->assertSame(AdminOrderStage::TONE_AMBER, $stage->tone);
        $this->assertFalse($stage->hasProblems());
    }

    public function testActiveContractWithoutIssuesIsGreen(): void
    {
        $order = $this->createOrder();
        $order->markPaid($this->now);
        $contract = $this->completeWithContract($order);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->scheduleNextBilling(new \DateTimeImmutable('2025-07-01'), null);

        $stage = $this->factory->build($order, $contract, null, null, 0, $this->now);

        $this->assertSame('Aktivní pronájem', $stage->label);
        $this->assertSame(AdminOrderStage::TONE_GREEN, $stage->tone);
        $this->assertFalse($stage->hasProblems());
        $this->assertNotNull($stage->nextStep);
        $this->assertStringContainsString('01.07.2025', $stage->nextStep);
    }

    public function testOverdueContractIsRedWithProblemDetail(): void
    {
        $order = $this->createOrder();
        $order->markPaid($this->now);
        $order->assignVariableSymbol('1234567890');
        $contract = $this->completeWithContract($order);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->scheduleNextBilling(new \DateTimeImmutable('2025-06-07'), null);

        $overdueView = new OverdueContractView(
            contract: $contract,
            daysOverdue: 8,
            overdueAmount: 288000,
            severity: OverdueSeverity::WARNING,
            reasonLabel: 'Zákazník nezaplatil výzvu',
            anchorDate: new \DateTimeImmutable('2025-06-07'),
        );

        $stage = $this->factory->build($order, $contract, $overdueView, null, 0, $this->now);

        $this->assertSame('Aktivní — platba po splatnosti', $stage->label);
        $this->assertSame(AdminOrderStage::TONE_RED, $stage->tone);
        $this->assertTrue($stage->hasProblems());
        $this->assertStringContainsString('2 880 Kč', $stage->problems[0]);
        $this->assertStringContainsString('8 dní', $stage->problems[0]);
        $this->assertStringContainsString('VS 1234567890', $stage->problems[0]);
    }

    public function testPaymentGraceShowsExtendedDeadline(): void
    {
        $order = $this->createOrder();
        $order->markPaid($this->now);
        $contract = $this->completeWithContract($order);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->scheduleNextBilling(new \DateTimeImmutable('2025-06-07'), null);
        $contract->extendPaymentDeadline(new \DateTimeImmutable('2025-06-20'), $this->now);

        $stage = $this->factory->build($order, $contract, null, null, 0, $this->now);

        $this->assertSame('Aktivní — splatnost prodloužena', $stage->label);
        $this->assertSame(AdminOrderStage::TONE_AMBER, $stage->tone);
        $this->assertSame('do 20.06.2025', $stage->sublabel);
    }

    public function testPendingManualRequestDrivesNextStep(): void
    {
        $order = $this->createOrder();
        $order->markPaid($this->now);
        $order->assignVariableSymbol('9876543210');
        $contract = $this->completeWithContract($order);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->scheduleNextBilling(new \DateTimeImmutable('2025-06-20'), null);

        $request = new ManualPaymentRequest(
            id: Uuid::v7(),
            contract: $contract,
            periodStart: new \DateTimeImmutable('2025-06-20'),
            periodEnd: new \DateTimeImmutable('2025-07-19'),
            amount: 288000,
            createdAt: $this->now,
        );

        $stage = $this->factory->build($order, $contract, null, $request, 0, $this->now);

        $this->assertNotNull($stage->nextStep);
        $this->assertStringContainsString('2 880 Kč', $stage->nextStep);
        $this->assertStringContainsString('VS 9876543210', $stage->nextStep);
    }

    public function testTerminatedForPaymentFailureIsRed(): void
    {
        $order = $this->createOrder();
        $order->markPaid($this->now);
        $contract = $this->completeWithContract($order);
        $contract->terminate($this->now, TerminationReason::PAYMENT_FAILURE);
        $contract->setOutstandingDebt(150000);

        $stage = $this->factory->build($order, $contract, null, null, 0, $this->now);

        $this->assertSame('Smlouva ukončena pro neplacení', $stage->label);
        $this->assertSame(AdminOrderStage::TONE_RED, $stage->tone);
        $this->assertTrue($stage->hasProblems());
        $this->assertStringContainsString('1 500 Kč', implode(' ', $stage->problems));
    }

    public function testPendingTerminationIsAmberWithEndDate(): void
    {
        $order = $this->createOrder();
        $order->markPaid($this->now);
        $contract = $this->completeWithContract($order);
        $contract->requestTermination($this->now, new \DateTimeImmutable('2025-07-31'));

        $stage = $this->factory->build($order, $contract, null, null, 0, $this->now);

        $this->assertSame('Aktivní — výpověď podána', $stage->label);
        $this->assertSame('končí 31.07.2025', $stage->sublabel);
        $this->assertSame(AdminOrderStage::TONE_AMBER, $stage->tone);
    }

    public function testCancelledOrderIsGray(): void
    {
        $order = $this->createOrder();
        $order->cancel($this->now);

        $stage = $this->factory->build($order, null, null, null, 0, $this->now);

        $this->assertSame('Objednávka zrušena', $stage->label);
        $this->assertSame(AdminOrderStage::TONE_GRAY, $stage->tone);
    }

    public function testFreeContractExplainsNothingIsBilled(): void
    {
        $order = $this->createOrder();
        $order->markPaid($this->now);
        $contract = $this->completeWithContract($order);
        $contract->applyIndividualMonthlyAmount(0, null, 'free', $this->now);

        $stage = $this->factory->build($order, $contract, null, null, 0, $this->now);

        $this->assertSame('Aktivní pronájem', $stage->label);
        $this->assertSame('Smlouva zdarma — nic se neúčtuje.', $stage->nextStep);
    }

    public function testFullyPrepaidContractExplainsNoFurtherBilling(): void
    {
        $order = $this->createOrder();
        $order->markPaid($this->now);
        $contract = $this->completeWithContract($order);
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->markExternallyPrepaid($contract->endDate);

        $stage = $this->factory->build($order, $contract, null, null, 0, $this->now);

        $this->assertNotNull($stage->nextStep);
        $this->assertStringContainsString('Zaplaceno do konce smlouvy', $stage->nextStep);
    }

    public function testSignedOnboardingOrderWaitingForBankTransfer(): void
    {
        $order = $this->createOrder();
        $order->markAsAdminCreated();
        $order->setSigningToken('token');
        $order->setPaymentMethod(PaymentMethod::BANK_TRANSFER);
        $order->assignVariableSymbol('5555555555');
        $order->attachSignature(
            signaturePath: '/tmp/sig.png',
            signingMethod: \App\Enum\SigningMethod::DRAW,
            typedName: null,
            styleId: null,
            signingPlace: 'Praha',
            signerIpAddress: null,
            signerUserAgent: null,
            now: $this->now,
        );
        $order->clearSigningToken();

        $stage = $this->factory->build($order, null, null, null, 0, $this->now);

        $this->assertSame('Podepsáno — čeká na platbu', $stage->label);
        $this->assertNotNull($stage->nextStep);
        $this->assertStringContainsString('VS 5555555555', $stage->nextStep);
    }

    private function createOrder(): Order
    {
        $user = new User(Uuid::v7(), 'user@example.com', 'password', 'Test', 'User', $this->now);
        $owner = new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', $this->now);

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
            owner: $owner,
        );

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-01'),
            endDate: new \DateTimeImmutable('2025-12-31'),
            firstPaymentPrice: 288000,
            expiresAt: new \DateTimeImmutable('2025-06-22'),
            createdAt: $this->now,
        );
    }

    private function completeWithContract(Order $order): Contract
    {
        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $order->user,
            storage: $order->storage,
            startDate: $order->startDate,
            endDate: $order->endDate ?? new \DateTimeImmutable('2025-12-31'),
            createdAt: $this->now,
        );
        $order->complete($contract->id, $this->now);

        return $contract;
    }
}
