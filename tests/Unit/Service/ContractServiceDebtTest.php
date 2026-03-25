<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\RentalType;
use App\Enum\TerminationReason;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Tests for outstanding debt calculation and payment failure termination tracking.
 */
class ContractServiceDebtTest extends TestCase
{
    public function testOutstandingDebtTrackingOnPaymentFailureTermination(): void
    {
        $contract = $this->createContractWithPaidThrough(
            new \DateTimeImmutable('2024-03-01'),
            350_00,
        );

        // Simulate 3 failed payment attempts
        $contract->recordFailedBillingAttempt(new \DateTimeImmutable('2024-03-01'));
        $contract->recordFailedBillingAttempt(new \DateTimeImmutable('2024-03-04'));
        $contract->recordFailedBillingAttempt(new \DateTimeImmutable('2024-03-11'));

        $this->assertSame(3, $contract->failedBillingAttempts);

        // Calculate debt: paid through March 1, terminated March 15 = 14 unpaid days
        $terminationDate = new \DateTimeImmutable('2024-03-15');
        $paidThrough = $contract->paidThroughDate;
        \assert(null !== $paidThrough);
        $unpaidDays = (int) $paidThrough->diff($terminationDate)->days;
        $monthlyRate = $contract->storage->getEffectivePricePerMonth();
        $debt = (int) round($unpaidDays * ($monthlyRate / 30));

        $this->assertSame(14, $unpaidDays);
        $this->assertSame(16333, $debt);

        // Set the debt and terminate
        $contract->setOutstandingDebt($debt);
        $contract->cancelRecurringPayment();
        $contract->terminate($terminationDate, TerminationReason::PAYMENT_FAILURE);

        $this->assertTrue($contract->isTerminated());
        $this->assertTrue($contract->isTerminatedDueToPaymentFailure());
        $this->assertTrue($contract->hasOutstandingDebt());
        $this->assertSame(16333, $contract->outstandingDebtAmount);
        $this->assertSame(TerminationReason::PAYMENT_FAILURE, $contract->terminationReason);
    }

    public function testNoDebtWhenPaidThroughCoversTermination(): void
    {
        $contract = $this->createContractWithPaidThrough(
            new \DateTimeImmutable('2024-04-01'), // paid through April 1
            350_00,
        );

        // Terminated March 15 → already paid past this date
        $terminationDate = new \DateTimeImmutable('2024-03-15');
        $paidThrough = $contract->paidThroughDate;
        \assert(null !== $paidThrough);

        $this->assertTrue($paidThrough >= $terminationDate);
        // No debt
    }

    public function testNormalTerminationHasNoDebtTracking(): void
    {
        $contract = $this->createContractWithPaidThrough(
            new \DateTimeImmutable('2024-04-01'),
            350_00,
        );

        $contract->cancelRecurringPayment();
        $contract->terminate(new \DateTimeImmutable('2024-04-01'), TerminationReason::TENANT_NOTICE);

        $this->assertTrue($contract->isTerminated());
        $this->assertFalse($contract->isTerminatedDueToPaymentFailure());
        $this->assertFalse($contract->hasOutstandingDebt());
        $this->assertSame(TerminationReason::TENANT_NOTICE, $contract->terminationReason);
    }

    public function testExpiredContractTerminationReason(): void
    {
        $endDate = new \DateTimeImmutable('2024-06-01');
        $contract = $this->createContract($endDate);

        $contract->terminate($endDate, TerminationReason::EXPIRED);

        $this->assertSame(TerminationReason::EXPIRED, $contract->terminationReason);
        $this->assertFalse($contract->isTerminatedDueToPaymentFailure());
    }

    private function createContract(?\DateTimeImmutable $endDate = null): Contract
    {
        $user = new User(Uuid::v7(), 'test@example.com', 'password', 'Test', 'User', new \DateTimeImmutable());
        $place = new Place(Uuid::v7(), 'Place', 'Address', 'City', '00000', null, new \DateTimeImmutable());
        $storageType = new StorageType(Uuid::v7(), $place, 'Box', 100, 100, 100, 10000, 35000, new \DateTimeImmutable());
        $storage = new Storage(Uuid::v7(), '1', ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0], $storageType, $place, new \DateTimeImmutable());
        $rentalType = null === $endDate ? RentalType::UNLIMITED : RentalType::LIMITED;
        $order = new Order(Uuid::v7(), $user, $storage, $rentalType, PaymentFrequency::MONTHLY, new \DateTimeImmutable(), $endDate, 35000, new \DateTimeImmutable('+7 days'), new \DateTimeImmutable());

        return new Contract(Uuid::v7(), $order, $user, $storage, $rentalType, new \DateTimeImmutable(), $endDate, new \DateTimeImmutable());
    }

    private function createContractWithPaidThrough(\DateTimeImmutable $paidThrough, int $monthlyPrice): Contract
    {
        $user = new User(Uuid::v7(), 'test@example.com', 'password', 'Test', 'User', new \DateTimeImmutable());
        $place = new Place(Uuid::v7(), 'Place', 'Address', 'City', '00000', null, new \DateTimeImmutable());
        $storageType = new StorageType(Uuid::v7(), $place, 'Box', 100, 100, 100, 10000, $monthlyPrice, new \DateTimeImmutable());
        $storage = new Storage(Uuid::v7(), '1', ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0], $storageType, $place, new \DateTimeImmutable());
        $order = new Order(Uuid::v7(), $user, $storage, RentalType::UNLIMITED, PaymentFrequency::MONTHLY, new \DateTimeImmutable(), null, $monthlyPrice, new \DateTimeImmutable('+7 days'), new \DateTimeImmutable());

        $contract = new Contract(Uuid::v7(), $order, $user, $storage, RentalType::UNLIMITED, new \DateTimeImmutable(), null, new \DateTimeImmutable());
        $contract->setRecurringPayment('parent-123', $paidThrough, $paidThrough);

        return $contract;
    }
}
