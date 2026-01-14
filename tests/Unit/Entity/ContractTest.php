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

    private function createStorage(User $owner): Storage
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Place',
            address: 'Test Address',
            city: 'Praha',
            postalCode: '110 00',
            description: null,
            owner: $owner,
            createdAt: new \DateTimeImmutable(),
        );

        $storageType = new StorageType(
            id: Uuid::v7(),
            name: 'Small Box',
            innerWidth: 100,
            innerHeight: 100,
            innerLength: 100,
            pricePerWeek: 10000,
            pricePerMonth: 35000,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        return new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createOrder(User $user, Storage $storage, ?\DateTimeImmutable $createdAt = null): Order
    {
        $createdAt ??= new \DateTimeImmutable();

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $createdAt->modify('+1 day'),
            endDate: $createdAt->modify('+30 days'),
            totalPrice: 50000,
            expiresAt: $createdAt->modify('+7 days'),
            createdAt: $createdAt,
        );
    }

    private function createContract(
        ?Order $order = null,
        ?User $user = null,
        ?Storage $storage = null,
        RentalType $rentalType = RentalType::LIMITED,
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

        return new Contract(
            id: Uuid::v7(),
            order: $order,
            user: $user,
            storage: $storage,
            rentalType: $rentalType,
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
            rentalType: RentalType::LIMITED,
            startDate: $startDate,
            endDate: $endDate,
            createdAt: $now,
        );

        $this->assertInstanceOf(Uuid::class, $contract->id);
        $this->assertSame($order, $contract->order);
        $this->assertSame($user, $contract->user);
        $this->assertSame($storage, $contract->storage);
        $this->assertSame(RentalType::LIMITED, $contract->rentalType);
        $this->assertSame($startDate, $contract->startDate);
        $this->assertSame($endDate, $contract->endDate);
        $this->assertSame($now, $contract->createdAt);
        $this->assertNull($contract->documentPath);
        $this->assertNull($contract->signedAt);
        $this->assertNull($contract->terminatedAt);
    }

    public function testCreateUnlimitedContract(): void
    {
        $contract = $this->createContract(rentalType: RentalType::UNLIMITED, endDate: null);

        $this->assertSame(RentalType::UNLIMITED, $contract->rentalType);
        $this->assertNull($contract->endDate);
        $this->assertTrue($contract->isUnlimited());
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

    public function testIsActiveForUnlimitedContractWithNoEndDate(): void
    {
        $contract = $this->createContract(rentalType: RentalType::UNLIMITED, endDate: null);

        $farFuture = new \DateTimeImmutable('2030-01-01');

        $this->assertTrue($contract->isActive($farFuture));
    }

    public function testIsUnlimited(): void
    {
        $unlimitedContract = $this->createContract(rentalType: RentalType::UNLIMITED, endDate: null);
        $this->assertTrue($unlimitedContract->isUnlimited());

        $limitedContract = $this->createContract(
            rentalType: RentalType::LIMITED,
            endDate: new \DateTimeImmutable('+30 days'),
        );
        $this->assertFalse($limitedContract->isUnlimited());
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
            rentalType: RentalType::LIMITED,
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
        $contract = $this->createContract(rentalType: RentalType::UNLIMITED, endDate: null);
        $nextBillingDate = new \DateTimeImmutable('2024-02-01');

        $contract->setRecurringPayment(12345, $nextBillingDate);

        $this->assertSame(12345, $contract->goPayParentPaymentId);
        $this->assertEquals($nextBillingDate, $contract->nextBillingDate);
        $this->assertTrue($contract->hasActiveRecurringPayment());
    }

    public function testHasActiveRecurringPaymentReturnsFalseWithoutSetup(): void
    {
        $contract = $this->createContract(rentalType: RentalType::UNLIMITED, endDate: null);

        $this->assertFalse($contract->hasActiveRecurringPayment());
    }

    public function testRecordBillingCharge(): void
    {
        $contract = $this->createContract(rentalType: RentalType::UNLIMITED, endDate: null);
        $contract->setRecurringPayment(12345, new \DateTimeImmutable('2024-02-01'));

        // Simulate a failed attempt first
        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());
        $this->assertSame(1, $contract->failedBillingAttempts);

        // Then successful charge
        $chargedAt = new \DateTimeImmutable('2024-02-01');
        $nextBillingDate = new \DateTimeImmutable('2024-03-01');

        $contract->recordBillingCharge($chargedAt, $nextBillingDate);

        $this->assertEquals($chargedAt, $contract->lastBilledAt);
        $this->assertEquals($nextBillingDate, $contract->nextBillingDate);
        $this->assertSame(0, $contract->failedBillingAttempts); // Reset on success
    }

    public function testRecordFailedBillingAttempt(): void
    {
        $contract = $this->createContract(rentalType: RentalType::UNLIMITED, endDate: null);
        $contract->setRecurringPayment(12345, new \DateTimeImmutable('2024-02-01'));

        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());

        $this->assertSame(1, $contract->failedBillingAttempts);
        $this->assertNotNull($contract->lastBillingFailedAt);

        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());

        $this->assertSame(2, $contract->failedBillingAttempts);
    }

    public function testCancelRecurringPayment(): void
    {
        $contract = $this->createContract(rentalType: RentalType::UNLIMITED, endDate: null);
        $contract->setRecurringPayment(12345, new \DateTimeImmutable('2024-02-01'));

        $this->assertTrue($contract->hasActiveRecurringPayment());

        $contract->cancelRecurringPayment();

        $this->assertFalse($contract->hasActiveRecurringPayment());
        $this->assertNull($contract->goPayParentPaymentId);
        $this->assertNull($contract->nextBillingDate);
    }

    public function testIsDueBilling(): void
    {
        $contract = $this->createContract(rentalType: RentalType::UNLIMITED, endDate: null);
        $contract->setRecurringPayment(12345, new \DateTimeImmutable('2024-02-01'));

        // Before due date
        $this->assertFalse($contract->isDueBilling(new \DateTimeImmutable('2024-01-31')));

        // On due date
        $this->assertTrue($contract->isDueBilling(new \DateTimeImmutable('2024-02-01')));

        // After due date
        $this->assertTrue($contract->isDueBilling(new \DateTimeImmutable('2024-02-15')));
    }

    public function testIsDueBillingReturnsFalseWithoutRecurringPayment(): void
    {
        $contract = $this->createContract(rentalType: RentalType::LIMITED, endDate: new \DateTimeImmutable('+30 days'));

        $this->assertFalse($contract->isDueBilling(new \DateTimeImmutable()));
    }

    public function testNeedsRetry(): void
    {
        $contract = $this->createContract(rentalType: RentalType::UNLIMITED, endDate: null);
        $contract->setRecurringPayment(12345, new \DateTimeImmutable('2024-02-01'));

        // Before failure
        $this->assertFalse($contract->needsRetry(new \DateTimeImmutable()));

        // After first failure
        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());

        // Still within 3 days
        $this->assertFalse($contract->needsRetry(new \DateTimeImmutable('+2 days')));

        // After 3 days
        $this->assertTrue($contract->needsRetry(new \DateTimeImmutable('+4 days')));
    }

    public function testNeedsRetryReturnsFalseAfterSecondFailure(): void
    {
        $contract = $this->createContract(rentalType: RentalType::UNLIMITED, endDate: null);
        $contract->setRecurringPayment(12345, new \DateTimeImmutable('2024-02-01'));

        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());
        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());

        // Even after 3 days, no more retries
        $this->assertFalse($contract->needsRetry(new \DateTimeImmutable('+5 days')));
    }
}
