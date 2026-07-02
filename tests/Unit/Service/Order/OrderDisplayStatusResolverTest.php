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
use App\Enum\TerminationReason;
use App\Service\Order\OrderDisplayStatusCase;
use App\Service\Order\OrderDisplayStatusResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

class OrderDisplayStatusResolverTest extends TestCase
{
    private OrderDisplayStatusResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new OrderDisplayStatusResolver();
    }

    public function testCreatedOrderIsAwaitingPayment(): void
    {
        $order = $this->createOrder();
        $status = $this->resolver->resolve($order, null);

        $this->assertSame(OrderDisplayStatusCase::AWAITING_PAYMENT, $status->case);
        $this->assertSame('warning', $status->variant);
        $this->assertSame('Čeká na platbu', $status->label);
    }

    public function testReservedOrderIsAwaitingPayment(): void
    {
        $order = $this->createOrder();
        $order->reserve(new \DateTimeImmutable());

        $status = $this->resolver->resolve($order, null);

        $this->assertSame(OrderDisplayStatusCase::AWAITING_PAYMENT, $status->case);
    }

    public function testPaidOrderIsProcessing(): void
    {
        $order = $this->createOrder();
        $order->reserve(new \DateTimeImmutable());
        $order->markPaid(new \DateTimeImmutable());

        $status = $this->resolver->resolve($order, null);

        $this->assertSame(OrderDisplayStatusCase::PROCESSING, $status->case);
        $this->assertSame('info', $status->variant);
    }

    public function testCompletedOrderWithActiveContractIsActive(): void
    {
        $order = $this->createOrder();
        $contract = $this->completeOrderAndCreateContract($order);

        $status = $this->resolver->resolve($order, $contract);

        $this->assertSame(OrderDisplayStatusCase::ACTIVE, $status->case);
        $this->assertSame('success', $status->variant);
        $this->assertSame('Aktivní', $status->label);
    }

    public function testCompletedOrderWithFailedBillingShowsBillingFailedBanner(): void
    {
        $order = $this->createOrder();
        $contract = $this->completeOrderAndCreateContract($order);
        $contract->recordFailedBillingAttempt(new \DateTimeImmutable());

        $status = $this->resolver->resolve($order, $contract);

        $this->assertSame(OrderDisplayStatusCase::ACTIVE_BILLING_FAILED, $status->case);
        $this->assertSame('warning', $status->variant);
    }

    public function testCompletedOrderWithPendingTerminationShowsNoticedBanner(): void
    {
        $order = $this->createOrder();
        $contract = $this->completeOrderAndCreateContract($order);
        $contract->requestTermination(new \DateTimeImmutable(), new \DateTimeImmutable('+30 days'));

        $status = $this->resolver->resolve($order, $contract);

        $this->assertSame(OrderDisplayStatusCase::ACTIVE_TERMINATION_PENDING, $status->case);
        $this->assertSame('info', $status->variant);
    }

    public function testCompletedOrderTerminatedForExpirationShowsCompletedEnded(): void
    {
        $order = $this->createOrder();
        $contract = $this->completeOrderAndCreateContract($order);
        $contract->terminate(new \DateTimeImmutable(), TerminationReason::EXPIRED, releaseStorage: false);

        $status = $this->resolver->resolve($order, $contract);

        $this->assertSame(OrderDisplayStatusCase::COMPLETED_ENDED, $status->case);
        $this->assertSame('Dokončeno', $status->label);
    }

    public function testCompletedOrderTerminatedForPaymentFailureShowsSuspended(): void
    {
        $order = $this->createOrder();
        $contract = $this->completeOrderAndCreateContract($order);
        $contract->terminate(new \DateTimeImmutable(), TerminationReason::PAYMENT_FAILURE, releaseStorage: false);

        $status = $this->resolver->resolve($order, $contract);

        $this->assertSame(OrderDisplayStatusCase::SUSPENDED_PAYMENT_FAILURE, $status->case);
        $this->assertSame('error', $status->variant);
    }

    public function testCompletedOrderTerminatedByTenantNoticeShowsTerminated(): void
    {
        $order = $this->createOrder();
        $contract = $this->completeOrderAndCreateContract($order);
        $contract->terminate(new \DateTimeImmutable(), TerminationReason::TENANT_NOTICE, releaseStorage: false);

        $status = $this->resolver->resolve($order, $contract);

        $this->assertSame(OrderDisplayStatusCase::TERMINATED, $status->case);
    }

    public function testCancelledOrderShowsCancelled(): void
    {
        $order = $this->createOrder();
        $order->reserve(new \DateTimeImmutable());
        $order->cancel(new \DateTimeImmutable());

        $status = $this->resolver->resolve($order, null);

        $this->assertSame(OrderDisplayStatusCase::CANCELLED, $status->case);
        $this->assertSame('Zrušeno', $status->label);
    }

    public function testExpiredOrderShowsExpired(): void
    {
        $order = $this->createOrder();
        $order->reserve(new \DateTimeImmutable());
        $order->expire(new \DateTimeImmutable());

        $status = $this->resolver->resolve($order, null);

        $this->assertSame(OrderDisplayStatusCase::EXPIRED, $status->case);
        $this->assertSame('Expirováno', $status->label);
    }

    private function createOrder(): Order
    {
        $user = new User(Uuid::v7(), 'test@example.com', 'pw', 'Jan', 'Novák', new \DateTimeImmutable());
        $place = new Place(Uuid::v7(), 'Pobočka', 'Adresa 1', 'Praha', '110 00', null, new \DateTimeImmutable());
        $storageType = new StorageType(Uuid::v7(), $place, 'Box', 100, 100, 100, 10000, 35000, 35000, 35000 * 12, new \DateTimeImmutable());
        $storage = new Storage(Uuid::v7(), 'A1', ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0, 'normalized' => true], $storageType, $place, new \DateTimeImmutable());

        $startDate = new \DateTimeImmutable();

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $startDate,
            endDate: $startDate->modify('+12 months'),
            firstPaymentPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function completeOrderAndCreateContract(Order $order): Contract
    {
        $order->reserve(new \DateTimeImmutable());
        $order->markPaid(new \DateTimeImmutable());
        $contractId = Uuid::v7();
        $order->complete($contractId, new \DateTimeImmutable());

        $contract = new Contract(
            id: $contractId,
            order: $order,
            user: $order->user,
            storage: $order->storage,
            startDate: $order->startDate,
            endDate: $order->startDate->modify('+12 months'),
            createdAt: new \DateTimeImmutable(),
        );
        $contract->setRecurringPayment('parent-123', new \DateTimeImmutable('+30 days'), new \DateTimeImmutable('+30 days'));

        return $contract;
    }
}
