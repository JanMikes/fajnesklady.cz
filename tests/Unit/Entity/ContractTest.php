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
use App\Enum\TerminationReason;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class ContractTest extends TestCase
{
    private function createUser(string $email = 'user@example.com'): User
    {
        return new User(Uuid::v7(), $email, 'password', 'Test', 'User', new \DateTimeImmutable());
    }

    private function createOwner(): User
    {
        return new User(Uuid::v7(), 'owner@example.com', 'password', 'Test', 'Owner', new \DateTimeImmutable());
    }

    private function createPlace(): Place
    {
        return new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createStorageType(): StorageType
    {
        return new StorageType(
            id: Uuid::v7(),
            place: $this->createPlace(),
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
    }

    private function createStorage(?User $owner = null): Storage
    {
        return new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $this->createStorageType(),
            place: $this->createPlace(),
            createdAt: new \DateTimeImmutable(),
            owner: $owner,
        );
    }

    private function createOrder(User $user, Storage $storage, ?\DateTimeImmutable $createdAt = null): Order
    {
        $createdAt ??= new \DateTimeImmutable();

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $createdAt->modify('+1 day'),
            endDate: $createdAt->modify('+30 days'),
            firstPaymentPrice: 50000,
            expiresAt: $createdAt->modify('+7 days'),
            createdAt: $createdAt,
        );
    }

    private function createContract(
        ?Order $order = null,
        ?User $user = null,
        ?Storage $storage = null,
        ?\DateTimeImmutable $startDate = null,
        ?\DateTimeImmutable $endDate = null,
        ?\DateTimeImmutable $createdAt = null,
    ): Contract {
        $owner = $this->createOwner();
        $user ??= $this->createUser();
        $storage ??= $this->createStorage($owner);
        $order ??= $this->createOrder($user, $storage);
        $createdAt ??= new \DateTimeImmutable();
        $startDate ??= $createdAt->modify('+1 day');
        $endDate ??= $startDate->modify('+12 months');

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: $createdAt,
        );
    }

    public function testCreateContract(): void
    {
        $now = new \DateTimeImmutable();
        $user = $this->createUser();
        $owner = $this->createOwner();
        $storage = $this->createStorage($owner);
        $order = $this->createOrder($user, $storage);
        $startDate = $now->modify('+1 day');
        $endDate = $now->modify('+30 days');

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: $now,
        );

        $this->assertInstanceOf(Uuid::class, $contract->id);
        $this->assertSame($order, $contract->order);
        $this->assertSame($user, $contract->user);
        $this->assertSame($storage, $contract->storage);
        $this->assertSame($startDate, $contract->startDate);
        $this->assertSame($endDate, $contract->endDate);
        $this->assertSame($now, $contract->createdAt);
        $this->assertNull($contract->documentPath);
        $this->assertNull($contract->signedAt);
        $this->assertNull($contract->terminatedAt);
    }

    public function testHasAvailabilityGuaranteeForAutoRecurringWithLiveToken(): void
    {
        $contract = $this->createContract();
        $contract->applyBillingMode(BillingMode::AUTO_RECURRING);

        // No GoPay token yet — no guarantee
        $this->assertFalse($contract->hasAvailabilityGuarantee());

        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2025-02-01'), new \DateTimeImmutable('2025-02-01'));

        $this->assertTrue($contract->hasAvailabilityGuarantee());
    }

    public function testHasAvailabilityGuaranteeFalseForManualRecurring(): void
    {
        $contract = $this->createContract();
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2025-02-01'), new \DateTimeImmutable('2025-02-01'));

        $this->assertFalse($contract->hasAvailabilityGuarantee());
    }

    public function testHasAvailabilityGuaranteeLostOnTermination(): void
    {
        $contract = $this->createContract();
        $contract->applyBillingMode(BillingMode::AUTO_RECURRING);
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2025-02-01'), new \DateTimeImmutable('2025-02-01'));
        $contract->terminate(new \DateTimeImmutable());

        $this->assertFalse($contract->hasAvailabilityGuarantee());
    }

    public function testHasAvailabilityGuaranteeLostOnPendingTermination(): void
    {
        $contract = $this->createContract();
        $contract->applyBillingMode(BillingMode::AUTO_RECURRING);
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2025-02-01'), new \DateTimeImmutable('2025-02-01'));
        $contract->requestTermination(new \DateTimeImmutable(), new \DateTimeImmutable('+1 month'));

        $this->assertFalse($contract->hasAvailabilityGuarantee());
    }

    public function testSign(): void
    {
        $contract = $this->createContract();
        $signedAt = new \DateTimeImmutable();

        $this->assertFalse($contract->isSigned());

        $contract->sign($signedAt);

        $this->assertTrue($contract->isSigned());
        $this->assertSame($signedAt, $contract->signedAt);
    }

    public function testTerminate(): void
    {
        $contract = $this->createContract();
        $terminatedAt = new \DateTimeImmutable();

        $this->assertFalse($contract->isTerminated());

        $contract->terminate($terminatedAt);

        $this->assertTrue($contract->isTerminated());
        $this->assertSame($terminatedAt, $contract->terminatedAt);
    }

    public function testTerminateReleasesStorage(): void
    {
        $owner = $this->createOwner();
        $user = $this->createUser();
        $storage = $this->createStorage($owner);
        $order = $this->createOrder($user, $storage);

        // First occupy the storage
        $storage->occupy(new \DateTimeImmutable());
        $this->assertTrue($storage->isOccupied());

        $contract = $this->createContract(order: $order, user: $user, storage: $storage);

        $contract->terminate(new \DateTimeImmutable());

        $this->assertTrue($storage->isAvailable());
    }

    public function testAttachDocument(): void
    {
        $contract = $this->createContract();
        $now = new \DateTimeImmutable();

        $this->assertFalse($contract->hasDocument());
        $this->assertNull($contract->documentPath);

        $contract->attachDocument('/path/to/contract.pdf', $now);

        $this->assertTrue($contract->hasDocument());
        $this->assertSame('/path/to/contract.pdf', $contract->documentPath);
    }

    public function testIsActiveWhenNotTerminatedAndBeforeEndDate(): void
    {
        $now = new \DateTimeImmutable('2024-01-15');
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2024-01-01'),
            endDate: new \DateTimeImmutable('2024-01-31'),
            createdAt: new \DateTimeImmutable('2024-01-01'),
        );

        $this->assertTrue($contract->isActive($now));
    }

    public function testIsNotActiveWhenTerminated(): void
    {
        $contract = $this->createContract(
            endDate: new \DateTimeImmutable('2024-12-31'),
        );
        $contract->terminate(new \DateTimeImmutable('2024-01-15'));

        $now = new \DateTimeImmutable('2024-01-20');

        $this->assertFalse($contract->isActive($now));
    }

    public function testIsNotActiveWhenPastEndDate(): void
    {
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2024-01-01'),
            endDate: new \DateTimeImmutable('2024-01-31'),
            createdAt: new \DateTimeImmutable('2024-01-01'),
        );

        $afterEndDate = new \DateTimeImmutable('2024-02-15');

        $this->assertFalse($contract->isActive($afterEndDate));
    }

    public function testIsSigned(): void
    {
        $contract = $this->createContract();

        $this->assertFalse($contract->isSigned());

        $contract->sign(new \DateTimeImmutable());

        $this->assertTrue($contract->isSigned());
    }

    public function testIsTerminated(): void
    {
        $contract = $this->createContract();

        $this->assertFalse($contract->isTerminated());

        $contract->terminate(new \DateTimeImmutable());

        $this->assertTrue($contract->isTerminated());
    }

    public function testHasDocument(): void
    {
        $contract = $this->createContract();

        $this->assertFalse($contract->hasDocument());

        $contract->attachDocument('/path/to/document.pdf', new \DateTimeImmutable());

        $this->assertTrue($contract->hasDocument());
    }

    public function testContractLifecycle(): void
    {
        // Full lifecycle: Create -> Sign -> Attach document -> Terminate
        $owner = $this->createOwner();
        $user = $this->createUser();
        $storage = $this->createStorage($owner);
        $order = $this->createOrder($user, $storage);

        // Occupy the storage for the contract
        $storage->occupy(new \DateTimeImmutable('2024-01-01'));

        $contract = new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            startDate: new \DateTimeImmutable('2024-01-01'),
            endDate: new \DateTimeImmutable('2024-12-31'),
            createdAt: new \DateTimeImmutable('2024-01-01'),
        );

        // Check initial state
        $this->assertFalse($contract->isSigned());
        $this->assertFalse($contract->hasDocument());
        $this->assertFalse($contract->isTerminated());
        $this->assertTrue($contract->isActive(new \DateTimeImmutable('2024-06-15')));
        $this->assertTrue($storage->isOccupied());

        // Sign the contract
        $contract->sign(new \DateTimeImmutable('2024-01-02'));
        $this->assertTrue($contract->isSigned());

        // Attach document
        $contract->attachDocument('/contracts/2024/contract-123.pdf', new \DateTimeImmutable('2024-01-02'));
        $this->assertTrue($contract->hasDocument());

        // Contract is still active
        $this->assertTrue($contract->isActive(new \DateTimeImmutable('2024-06-15')));

        // Terminate contract
        $contract->terminate(new \DateTimeImmutable('2024-06-30'));
        $this->assertTrue($contract->isTerminated());
        $this->assertFalse($contract->isActive(new \DateTimeImmutable('2024-07-01')));
        $this->assertTrue($storage->isAvailable()); // Storage released
    }

    public function testSetRecurringPayment(): void
    {
        $contract = $this->createContract();
        $nextBillingDate = new \DateTimeImmutable('2024-02-01');

        $contract->setRecurringPayment('12345', $nextBillingDate, $nextBillingDate);

        $this->assertSame('12345', $contract->goPayParentPaymentId);
        $this->assertEquals($nextBillingDate, $contract->nextBillingDate);
        $this->assertTrue($contract->hasActiveRecurringPayment());
    }

    public function testHasActiveRecurringPaymentReturnsFalseWithoutSetup(): void
    {
        $contract = $this->createContract();

        $this->assertFalse($contract->hasActiveRecurringPayment());
    }

    public function testRecordBillingCharge(): void
    {
        $contract = $this->createContract();
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2024-02-01'), new \DateTimeImmutable('2024-02-01'));

        // Simulate a failed attempt first
        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());
        $this->assertSame(1, $contract->failedBillingAttempts);

        // Then successful charge
        $chargedAt = new \DateTimeImmutable('2024-02-01');
        $nextBillingDate = new \DateTimeImmutable('2024-03-01');

        $contract->recordBillingCharge($chargedAt, $nextBillingDate, $nextBillingDate);

        $this->assertEquals($chargedAt, $contract->lastBilledAt);
        $this->assertEquals($nextBillingDate, $contract->nextBillingDate);
        $this->assertSame(0, $contract->failedBillingAttempts); // Reset on success
    }

    public function testRecordFailedBillingAttempt(): void
    {
        $contract = $this->createContract();
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2024-02-01'), new \DateTimeImmutable('2024-02-01'));

        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());

        $this->assertSame(1, $contract->failedBillingAttempts);
        $this->assertNotNull($contract->lastBillingFailedAt);

        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());

        $this->assertSame(2, $contract->failedBillingAttempts);
    }

    public function testCancelRecurringPayment(): void
    {
        $contract = $this->createContract();
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2024-02-01'), new \DateTimeImmutable('2024-02-01'));

        $this->assertTrue($contract->hasActiveRecurringPayment());

        $contract->cancelRecurringPayment();

        $this->assertFalse($contract->hasActiveRecurringPayment());
        $this->assertNull($contract->goPayParentPaymentId);
        $this->assertNull($contract->nextBillingDate);
    }

    public function testIsDueBilling(): void
    {
        $contract = $this->createContract();
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2024-02-01'), new \DateTimeImmutable('2024-02-01'));

        // Before due date
        $this->assertFalse($contract->isDueBilling(new \DateTimeImmutable('2024-01-31')));

        // On due date
        $this->assertTrue($contract->isDueBilling(new \DateTimeImmutable('2024-02-01')));

        // After due date
        $this->assertTrue($contract->isDueBilling(new \DateTimeImmutable('2024-02-15')));
    }

    public function testIsDueBillingReturnsFalseWithoutRecurringPayment(): void
    {
        $contract = $this->createContract(endDate: new \DateTimeImmutable('+30 days'));

        $this->assertFalse($contract->isDueBilling(new \DateTimeImmutable()));
    }

    public function testNeedsRetry(): void
    {
        $contract = $this->createContract();
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2024-02-01'), new \DateTimeImmutable('2024-02-01'));

        // Before failure
        $this->assertFalse($contract->needsRetry(new \DateTimeImmutable()));

        // After first failure
        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());

        // Still within 3 days
        $this->assertFalse($contract->needsRetry(new \DateTimeImmutable('+2 days')));

        // After 3 days
        $this->assertTrue($contract->needsRetry(new \DateTimeImmutable('+4 days')));
    }

    public function testNeedsRetryAfterSecondFailure(): void
    {
        $contract = $this->createContract();
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2024-02-01'), new \DateTimeImmutable('2024-02-01'));

        $failedAt = new \DateTimeImmutable('2024-02-01');
        $contract->recordFailedBillingAttempt($failedAt);
        $contract->recordFailedBillingAttempt($failedAt);

        // Within 4 days - no retry yet (VOP XI: day 3 + 4 = day 7)
        $this->assertFalse($contract->needsRetry($failedAt->modify('+3 days')));

        // After 4 days - retry (2nd attempt → terminates at day 7)
        $this->assertTrue($contract->needsRetry($failedAt->modify('+5 days')));
    }

    public function testNeedsRetryReturnsFalseAfterThirdFailure(): void
    {
        $contract = $this->createContract();
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2024-02-01'), new \DateTimeImmutable('2024-02-01'));

        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());
        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());
        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());

        // After 3 failures, no more retries
        $this->assertFalse($contract->needsRetry(new \DateTimeImmutable('+30 days')));
    }

    public function testTerminateWithReason(): void
    {
        $contract = $this->createContract();
        $now = new \DateTimeImmutable();

        $contract->terminate($now, TerminationReason::PAYMENT_FAILURE);

        $this->assertTrue($contract->isTerminated());
        $this->assertSame(TerminationReason::PAYMENT_FAILURE, $contract->terminationReason);
        $this->assertTrue($contract->isTerminatedDueToPaymentFailure());
    }

    public function testTerminateDefaultReasonIsExpired(): void
    {
        $contract = $this->createContract(endDate: new \DateTimeImmutable('+30 days'));
        $now = new \DateTimeImmutable();

        $contract->terminate($now);

        $this->assertSame(TerminationReason::EXPIRED, $contract->terminationReason);
        $this->assertFalse($contract->isTerminatedDueToPaymentFailure());
    }

    public function testOutstandingDebt(): void
    {
        $contract = $this->createContract();

        $this->assertFalse($contract->hasOutstandingDebt());

        $contract->setOutstandingDebt(150000); // 1500 CZK

        $this->assertTrue($contract->hasOutstandingDebt());
        $this->assertSame(150000, $contract->outstandingDebtAmount);
    }

    public function testOutstandingDebtZeroIsNotDebt(): void
    {
        $contract = $this->createContract();
        $contract->setOutstandingDebt(0);

        $this->assertFalse($contract->hasOutstandingDebt());
    }

    public function testRequestTermination(): void
    {
        $contract = $this->createContract();
        $noticedAt = new \DateTimeImmutable('2024-03-01');
        $terminatesAt = new \DateTimeImmutable('2024-04-01');

        $contract->requestTermination($noticedAt, $terminatesAt);

        $this->assertTrue($contract->hasPendingTermination());
        $this->assertEquals($noticedAt, $contract->terminationNoticedAt);
        $this->assertEquals($terminatesAt, $contract->terminatesAt);
        $this->assertFalse($contract->isTerminated());
    }

    public function testRequestTerminationThrowsIfAlreadyTerminated(): void
    {
        $contract = $this->createContract();
        $contract->terminate(new \DateTimeImmutable());

        $this->expectException(\DomainException::class);
        $contract->requestTermination(new \DateTimeImmutable(), new \DateTimeImmutable('+1 month'));
    }

    public function testRequestTerminationThrowsIfAlreadyPending(): void
    {
        $contract = $this->createContract();
        $contract->requestTermination(new \DateTimeImmutable(), new \DateTimeImmutable('+1 month'));

        $this->expectException(\DomainException::class);
        $contract->requestTermination(new \DateTimeImmutable(), new \DateTimeImmutable('+1 month'));
    }

    public function testIsTerminationDue(): void
    {
        $contract = $this->createContract();
        $contract->requestTermination(
            new \DateTimeImmutable('2024-03-01'),
            new \DateTimeImmutable('2024-04-01'),
        );

        $this->assertFalse($contract->isTerminationDue(new \DateTimeImmutable('2024-03-15')));
        $this->assertTrue($contract->isTerminationDue(new \DateTimeImmutable('2024-04-01')));
        $this->assertTrue($contract->isTerminationDue(new \DateTimeImmutable('2024-04-15')));
    }

    public function testEffectiveEndDateReturnsEndDate(): void
    {
        $endDate = new \DateTimeImmutable('+30 days');
        $contract = $this->createContract(endDate: $endDate);

        $this->assertEquals($endDate, $contract->getEffectiveEndDate());
    }

    public function testEffectiveEndDateReturnsTerminatesAtWhenTerminationPending(): void
    {
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: new \DateTimeImmutable('2026-01-01'),
        );
        $terminatesAt = new \DateTimeImmutable('2025-04-01');
        $contract->requestTermination(new \DateTimeImmutable('2025-03-01'), $terminatesAt);

        $this->assertEquals($terminatesAt, $contract->getEffectiveEndDate());
    }

    public function testGetEffectiveMonthlyAmountFallsBackToStorage(): void
    {
        $contract = $this->createContract();

        // Storage default is 35000 (350 Kč)
        $this->assertSame(35000, $contract->getEffectiveMonthlyAmount());
        $this->assertNull($contract->individualMonthlyAmount);
        $this->assertFalse($contract->hasIndividualPrice());
        $this->assertFalse($contract->isFree());
    }

    public function testApplyIndividualMonthlyAmountTakesPrecedenceOverStorage(): void
    {
        $contract = $this->createContract();

        $contract->applyIndividualMonthlyAmount(80_000, null, null, new \DateTimeImmutable());

        $this->assertSame(80_000, $contract->getEffectiveMonthlyAmount());
        $this->assertTrue($contract->hasIndividualPrice());
        $this->assertFalse($contract->isFree());
    }

    public function testApplyIndividualMonthlyAmountZeroIsFree(): void
    {
        $contract = $this->createContract();

        $contract->applyIndividualMonthlyAmount(0, null, null, new \DateTimeImmutable());

        $this->assertSame(0, $contract->getEffectiveMonthlyAmount());
        $this->assertTrue($contract->hasIndividualPrice());
        $this->assertTrue($contract->isFree());
    }

    public function testApplyIndividualMonthlyAmountRejectsNegative(): void
    {
        $contract = $this->createContract();

        $this->expectException(\InvalidArgumentException::class);
        $contract->applyIndividualMonthlyAmount(-1, null, null, new \DateTimeImmutable());
    }

    public function testApplyIndividualMonthlyAmountRejectsAboveLegalCap(): void
    {
        $contract = $this->createContract();

        $this->expectException(\DomainException::class);
        // 16 000 Kč > 15 000 Kč legal max for recurring payments
        $contract->applyIndividualMonthlyAmount(1_600_000, null, null, new \DateTimeImmutable());
    }

    public function testApplyIndividualMonthlyAmountAtCapIsAccepted(): void
    {
        $contract = $this->createContract();

        $contract->applyIndividualMonthlyAmount(1_500_000, null, null, new \DateTimeImmutable());

        $this->assertSame(1_500_000, $contract->getEffectiveMonthlyAmount());
    }

    public function testYearlyContractIndividualAmountMayExceedRecurringCap(): void
    {
        // The 15 000 Kč cap binds recurring CARD charges; yearly contracts are
        // bank-transfer only and their individual amount is a per-YEAR figure.
        $contract = $this->createContract();
        $contract->applyPaymentFrequency(PaymentFrequency::YEARLY);

        $contract->applyIndividualMonthlyAmount(2_400_000, null, null, new \DateTimeImmutable());

        $this->assertSame(2_400_000, $contract->individualMonthlyAmount);
    }

    public function testYearlyContractRecurringAmountUsesIndividualYearlyFigure(): void
    {
        $contract = $this->createContract();
        $contract->applyPaymentFrequency(PaymentFrequency::YEARLY);
        $contract->applyIndividualMonthlyAmount(2_400_000, null, null, new \DateTimeImmutable());

        $this->assertSame(2_400_000, $contract->getEffectiveRecurringAmount());
        // Monthly-equivalent projection (debt proration, e-mail displays).
        $this->assertSame(200_000, $contract->getEffectiveMonthlyAmount());
    }

    public function testYearlyContractRecurringAmountFallsBackToStorageYearlyRate(): void
    {
        $contract = $this->createContract();
        $contract->applyPaymentFrequency(PaymentFrequency::YEARLY);

        // Storage type default yearly rate is 35 000 × 12 = 420 000 haléřů.
        $this->assertSame(420_000, $contract->getEffectiveRecurringAmount());
    }

    public function testApplyIndividualMonthlyAmountNullClearsOverride(): void
    {
        $contract = $this->createContract();
        $contract->applyIndividualMonthlyAmount(80_000, null, null, new \DateTimeImmutable());

        $contract->applyIndividualMonthlyAmount(null, null, null, new \DateTimeImmutable());

        $this->assertNull($contract->individualMonthlyAmount);
        $this->assertFalse($contract->hasIndividualPrice());
    }

    public function testIsFreeReturnsFalseWhenIndividualAmountIsNull(): void
    {
        $contract = $this->createContract();

        // null !== 0 — null means "use storage default", not free
        $this->assertFalse($contract->isFree());
    }

    public function testMarkExternallyPrepaidSetsBothDates(): void
    {
        $contract = $this->createContract();
        $paidThroughDate = new \DateTimeImmutable('2026-12-31');

        $contract->markExternallyPrepaid($paidThroughDate);

        $this->assertEquals($paidThroughDate, $contract->paidThroughDate);
        $this->assertEquals($paidThroughDate->modify('+1 day'), $contract->nextBillingDate);
        $this->assertNull($contract->goPayParentPaymentId);
    }

    public function testMarkExternallyPrepaidToContractEndLeavesNoBillingAnchor(): void
    {
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2025-07-01'),
            endDate: new \DateTimeImmutable('2026-07-01'),
        );

        $contract->markExternallyPrepaid(new \DateTimeImmutable('2026-07-01'));

        $this->assertEquals(new \DateTimeImmutable('2026-07-01'), $contract->paidThroughDate);
        $this->assertNull($contract->nextBillingDate, 'nothing to bill — no anchor for the manual cron or the overdue sweep');
    }

    public function testMarkExternallyPrepaidLastDayBeforeEndKeepsAnchorOnEndDate(): void
    {
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2025-07-01'),
            endDate: new \DateTimeImmutable('2026-07-01'),
        );

        $contract->markExternallyPrepaid(new \DateTimeImmutable('2026-06-30'));

        $this->assertEquals(new \DateTimeImmutable('2026-07-01'), $contract->nextBillingDate);
    }

    public function testDaysUntilExternalPrepaymentEndsReturnsNullWhenNotPrepaid(): void
    {
        $contract = $this->createContract();

        $this->assertNull($contract->daysUntilExternalPrepaymentEnds(new \DateTimeImmutable('2025-06-15 12:00:00')));
    }

    public function testDaysUntilExternalPrepaymentEndsReturnsNullWhenAlreadyConvertedToGoPay(): void
    {
        $contract = $this->createContract();
        $paidThroughDate = new \DateTimeImmutable('2025-07-15');
        $contract->markExternallyPrepaid($paidThroughDate);
        // Customer has converted to GoPay — the externally-prepaid state ends.
        $contract->setRecurringPayment('gopay-parent-1', new \DateTimeImmutable('2025-08-15'), $paidThroughDate);

        $this->assertNull($contract->daysUntilExternalPrepaymentEnds(new \DateTimeImmutable('2025-06-15 12:00:00')));
    }

    public function testDaysUntilExternalPrepaymentEndsReturnsNullWhenContractTerminated(): void
    {
        $contract = $this->createContract();
        $contract->markExternallyPrepaid(new \DateTimeImmutable('2025-07-15'));
        $contract->terminate(new \DateTimeImmutable('2025-06-10'));

        $this->assertNull($contract->daysUntilExternalPrepaymentEnds(new \DateTimeImmutable('2025-06-15 12:00:00')));
    }

    public function testDaysUntilExternalPrepaymentEndsReturnsPositiveForFutureDate(): void
    {
        $contract = $this->createContract();
        $contract->markExternallyPrepaid(new \DateTimeImmutable('2025-06-20'));

        $this->assertSame(5, $contract->daysUntilExternalPrepaymentEnds(new \DateTimeImmutable('2025-06-15 12:00:00')));
    }

    public function testDaysUntilExternalPrepaymentEndsReturnsZeroOnExpirationDay(): void
    {
        $contract = $this->createContract();
        $contract->markExternallyPrepaid(new \DateTimeImmutable('2025-06-15'));

        $this->assertSame(0, $contract->daysUntilExternalPrepaymentEnds(new \DateTimeImmutable('2025-06-15 12:00:00')));
    }

    public function testDaysUntilExternalPrepaymentEndsReturnsNegativeForPastDate(): void
    {
        $contract = $this->createContract();
        $contract->markExternallyPrepaid(new \DateTimeImmutable('2025-06-13'));

        $this->assertSame(-2, $contract->daysUntilExternalPrepaymentEnds(new \DateTimeImmutable('2025-06-15 12:00:00')));
    }

    public function testDaysUntilExternalPrepaymentEndsIgnoresTimeOfDayWithinSameCalendarDay(): void
    {
        $contract = $this->createContract();
        $contract->markExternallyPrepaid(new \DateTimeImmutable('2025-06-15 00:00:00'));

        // Late in the same calendar day must still resolve to 0, not -1.
        $this->assertSame(0, $contract->daysUntilExternalPrepaymentEnds(new \DateTimeImmutable('2025-06-15 23:59:59')));
    }

    // -- Spec 055: payment demand + billing charge reset ----------------------

    public function testRecordPaymentDemandSent(): void
    {
        $contract = $this->createContract();
        $now = new \DateTimeImmutable();

        $this->assertNull($contract->paymentDemandSentAt);

        $contract->recordPaymentDemandSent($now);

        $this->assertSame($now, $contract->paymentDemandSentAt);
    }

    public function testRecordBillingChargeClearsPaymentDemand(): void
    {
        $contract = $this->createContract();
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2024-02-01'), new \DateTimeImmutable('2024-02-01'));
        $contract->recordPaymentDemandSent(new \DateTimeImmutable());

        $this->assertNotNull($contract->paymentDemandSentAt);

        $contract->recordBillingCharge(
            new \DateTimeImmutable('2024-02-01'),
            new \DateTimeImmutable('2024-03-01'),
            new \DateTimeImmutable('2024-03-01'),
        );

        $this->assertNull($contract->paymentDemandSentAt);
    }

    public function testNeedsRetryVopCompliantTimingDay3ThenDay7(): void
    {
        $contract = $this->createContract();
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2024-02-01'), new \DateTimeImmutable('2024-02-01'));

        // Day 0: initial failure
        $day0 = new \DateTimeImmutable('2024-02-01');
        $contract->recordFailedBillingAttempt($day0);
        $this->assertSame(1, $contract->failedBillingAttempts);

        // Day 2: no retry yet
        $this->assertFalse($contract->needsRetry($day0->modify('+2 days')));
        // Day 3: first retry
        $this->assertTrue($contract->needsRetry($day0->modify('+3 days')));

        // Simulate first retry also fails at day 3
        $day3 = $day0->modify('+3 days');
        $contract->recordFailedBillingAttempt($day3);
        $this->assertSame(2, $contract->failedBillingAttempts);

        // Day 3+3 = day 6: no retry yet
        $this->assertFalse($contract->needsRetry($day3->modify('+3 days')));
        // Day 3+4 = day 7: second retry (terminates if fails)
        $this->assertTrue($contract->needsRetry($day3->modify('+4 days')));
    }

    // -- Spec 045: yearly cadence --------------------------------------------

    public function testCadenceStepDefaultsToOneMonth(): void
    {
        $contract = $this->createContract();

        $this->assertSame('+1 month', $contract->getBillingCadenceStep());
        $this->assertSame(30, $contract->getBillingPeriodDays());
        $this->assertFalse($contract->isYearly());
    }

    public function testCadenceStepSwitchesToOneYearForYearlyContracts(): void
    {
        $contract = $this->createContract();
        $contract->applyPaymentFrequency(PaymentFrequency::YEARLY);

        $this->assertSame('+1 year', $contract->getBillingCadenceStep());
        $this->assertSame(365, $contract->getBillingPeriodDays());
        $this->assertTrue($contract->isYearly());
    }

    public function testYearlyContractSuppressesExternalPrepaymentBanner(): void
    {
        $contract = $this->createContract();
        $contract->applyPaymentFrequency(PaymentFrequency::YEARLY);
        // paidThroughDate gets populated after each yearly charge but yearly
        // contracts must not be treated as "externally prepaid".
        $contract->markExternallyPrepaid(new \DateTimeImmutable('2027-06-15'));

        $this->assertNull(
            $contract->daysUntilExternalPrepaymentEnds(new \DateTimeImmutable('2026-06-15')),
        );
    }

    // -- Spec 058/076: always "doba určitá", no auto-extension ----------------

    public function testRecordBillingChargeDoesNotExtendEndDateForOneTime(): void
    {
        $endDate = new \DateTimeImmutable('2025-02-01');
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: $endDate,
        );
        $contract->applyBillingMode(BillingMode::ONE_TIME);

        $contract->recordBillingCharge(
            new \DateTimeImmutable('2025-02-01'),
            null,
            new \DateTimeImmutable('2025-03-01'),
        );

        $this->assertEquals($endDate, $contract->endDate);
    }

    public function testRecordBillingChargeDoesNotExtendEndDateForAutoRecurring(): void
    {
        $endDate = new \DateTimeImmutable('2025-07-01');
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: $endDate,
        );
        $contract->applyBillingMode(BillingMode::AUTO_RECURRING);
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2025-02-01'), new \DateTimeImmutable('2025-02-01'));

        $contract->recordBillingCharge(
            new \DateTimeImmutable('2025-02-01'),
            new \DateTimeImmutable('2025-03-01'),
            new \DateTimeImmutable('2025-03-01'),
        );

        $this->assertEquals($endDate, $contract->endDate);
    }

    public function testIsActiveReturnsTrueForAutoRecurringInGrace(): void
    {
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: new \DateTimeImmutable('2025-02-01'),
        );
        $contract->applyBillingMode(BillingMode::AUTO_RECURRING);
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2025-02-01'), new \DateTimeImmutable('2025-02-01'));

        // Past endDate but GoPay token still active
        $this->assertTrue($contract->isActive(new \DateTimeImmutable('2025-02-15')));
    }

    public function testIsActiveReturnsFalseForAutoRecurringAfterTokenRevoked(): void
    {
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: new \DateTimeImmutable('2025-02-01'),
        );
        $contract->applyBillingMode(BillingMode::AUTO_RECURRING);
        $contract->setRecurringPayment('12345', new \DateTimeImmutable('2025-02-01'), new \DateTimeImmutable('2025-02-01'));
        $contract->cancelRecurringPayment();

        // Past endDate, GoPay token revoked → no grace
        $this->assertFalse($contract->isActive(new \DateTimeImmutable('2025-02-15')));
    }

    public function testIsActiveReturnsTrueForManualRecurringInGrace(): void
    {
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: new \DateTimeImmutable('2025-02-01'),
        );
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->recordBillingCharge(
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2025-02-01'),
            new \DateTimeImmutable('2025-02-01'),
        );

        // Past endDate but nextBillingDate is set
        $this->assertTrue($contract->isActive(new \DateTimeImmutable('2025-02-15')));
    }

    public function testIsActiveReturnsFalseForManualRecurringNoGrace(): void
    {
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: new \DateTimeImmutable('2025-02-01'),
        );
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->cancelRecurringPayment();

        // Past endDate, no nextBillingDate, no retries → no grace
        $this->assertFalse($contract->isActive(new \DateTimeImmutable('2025-02-15')));
    }

    public function testIsActiveReturnsFalseForOneTimePastEndDate(): void
    {
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: new \DateTimeImmutable('2025-02-01'),
        );
        $contract->applyBillingMode(BillingMode::ONE_TIME);

        $this->assertFalse($contract->isActive(new \DateTimeImmutable('2025-02-15')));
    }

    public function testIsInBillingGraceForManualRecurringWithFailedAttempts(): void
    {
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: new \DateTimeImmutable('2025-02-01'),
        );
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->cancelRecurringPayment();
        $contract->recordFailedBillingAttempt(new \DateTimeImmutable('2025-02-01'));

        // Past endDate, failedBillingAttempts > 0 → still in grace
        $this->assertTrue($contract->isInBillingGrace());
        $this->assertTrue($contract->isActive(new \DateTimeImmutable('2025-02-15')));
    }

    // -- Spec 078 tranches: upfront rentals > 12 months -----------------------

    public function testCadenceStepIsOneYearForUpfrontContract(): void
    {
        $contract = $this->createContract();
        $contract->applyBillingMode(BillingMode::ONE_TIME);
        $contract->applyPaymentFrequency(PaymentFrequency::ONE_TIME);

        $this->assertSame('+1 year', $contract->getBillingCadenceStep());
    }

    public function testCadenceStepFallsBackToMonthlyAfterProlongationConversion(): void
    {
        // Spec 077: prolongation converts ONE_TIME → MANUAL_RECURRING; extension
        // cycles are monthly even though the frequency stays locked at ONE_TIME.
        $contract = $this->createContract();
        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $contract->applyPaymentFrequency(PaymentFrequency::ONE_TIME);

        $this->assertSame('+1 month', $contract->getBillingCadenceStep());
    }

    public function testUsesManualBillingTrack(): void
    {
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: new \DateTimeImmutable('2026-04-01'),
        );

        $contract->applyBillingMode(BillingMode::MANUAL_RECURRING);
        $this->assertTrue($contract->usesManualBillingTrack());

        $contract->applyBillingMode(BillingMode::AUTO_RECURRING);
        $this->assertFalse($contract->usesManualBillingTrack());

        // Upfront with no anchor (≤ 12 months or fully paid): outside the track.
        $contract->applyBillingMode(BillingMode::ONE_TIME);
        $this->assertFalse($contract->usesManualBillingTrack());

        // Upfront with an outstanding tranche (anchor set): inside the track.
        $contract->recordBillingCharge(
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-01'),
        );
        $this->assertTrue($contract->usesManualBillingTrack());
    }

    public function testUpfrontContractWithUnpaidTrancheStaysInGrace(): void
    {
        $contract = $this->createContract(
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: new \DateTimeImmutable('2026-01-10'),
        );
        $contract->applyBillingMode(BillingMode::ONE_TIME);
        $contract->applyPaymentFrequency(PaymentFrequency::ONE_TIME);
        // Anchor on the final (tail) tranche, still unpaid past endDate.
        $contract->recordBillingCharge(
            new \DateTimeImmutable('2025-01-01'),
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-01-01'),
        );

        $this->assertTrue($contract->isInBillingGrace());
        $this->assertTrue($contract->isActive(new \DateTimeImmutable('2026-01-15')));
    }

    public function testUpfrontContractSuppressesExternalPrepaymentBanner(): void
    {
        $contract = $this->createContract();
        $contract->applyBillingMode(BillingMode::ONE_TIME);
        $contract->applyPaymentFrequency(PaymentFrequency::ONE_TIME);
        // paidThroughDate tracks paid tranches, not an external prepayment.
        $contract->recordBillingCharge(
            new \DateTimeImmutable('2025-06-15'),
            new \DateTimeImmutable('2026-06-15'),
            new \DateTimeImmutable('2026-06-15'),
        );

        $this->assertNull(
            $contract->daysUntilExternalPrepaymentEnds(new \DateTimeImmutable('2026-06-10')),
        );
    }

    public function testEffectiveMonthlyAmountUsesLongTermRateFromThreshold(): void
    {
        $storageType = new StorageType(
            id: Uuid::v7(),
            place: $this->createPlace(),
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 40000,
            defaultPricePerMonthLongTerm: 35000,
            defaultPricePerYear: 35000 * 12,
            createdAt: new \DateTimeImmutable(),
        );
        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $this->createPlace(),
            createdAt: new \DateTimeImmutable(),
            owner: $this->createOwner(),
        );
        $user = $this->createUser();
        $order = $this->createOrder($user, $storage);

        // 31 days < 180-day threshold → standard monthly rate
        $shortTerm = $this->createContract(
            order: $order,
            user: $user,
            storage: $storage,
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: new \DateTimeImmutable('2025-02-01'),
        );
        $this->assertSame(40000, $shortTerm->getEffectiveMonthlyAmount());

        // 365 days >= 180-day threshold → long-term monthly rate
        $longTerm = $this->createContract(
            order: $order,
            user: $user,
            storage: $storage,
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: new \DateTimeImmutable('2026-01-01'),
        );
        $this->assertSame(35000, $longTerm->getEffectiveMonthlyAmount());
    }
}
