<?php

declare(strict_types=1);

namespace App\Tests\Unit\Event;

use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\RentalType;
use App\Event\IssueInvoiceOnPaymentHandler;
use App\Event\OrderPaid;
use App\Repository\OrderRepository;
use App\Service\InvoicingService;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

class IssueInvoiceOnPaymentHandlerTest extends TestCase
{
    public function testHandlerFetchesOrderAndIssuesInvoice(): void
    {
        $orderId = Uuid::v7();
        $now = new \DateTimeImmutable('2025-06-15 12:00:00');
        $order = $this->createOrder($orderId);
        $event = new OrderPaid($orderId, $now);

        $orderRepository = $this->createMock(OrderRepository::class);
        $orderRepository->expects($this->once())
            ->method('get')
            ->with($orderId)
            ->willReturn($order);

        $invoicingService = $this->createMock(InvoicingService::class);
        $invoicingService->expects($this->once())
            ->method('issueInvoiceForOrder')
            ->with($order, $now);

        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn($now);

        $handler = new IssueInvoiceOnPaymentHandler(
            $orderRepository,
            $invoicingService,
            $clock,
            new NullLogger(),
        );

        $handler($event);
    }

    public function testHandlerUsesCurrentTimeFromClock(): void
    {
        $orderId = Uuid::v7();
        $eventTime = new \DateTimeImmutable('2025-06-15 10:00:00');
        $clockTime = new \DateTimeImmutable('2025-06-15 14:30:00');
        $order = $this->createOrder($orderId);
        $event = new OrderPaid($orderId, $eventTime);

        $orderRepository = $this->createMock(OrderRepository::class);
        $orderRepository->method('get')->willReturn($order);

        $invoicingService = $this->createMock(InvoicingService::class);
        $invoicingService->expects($this->once())
            ->method('issueInvoiceForOrder')
            ->with($order, $clockTime);

        $clock = $this->createMock(ClockInterface::class);
        $clock->method('now')->willReturn($clockTime);

        $handler = new IssueInvoiceOnPaymentHandler(
            $orderRepository,
            $invoicingService,
            $clock,
            new NullLogger(),
        );

        $handler($event);
    }

    private function createOrder(Uuid $orderId): Order
    {
        $place = new Place(
            id: Uuid::v7(),
            name: 'Test Warehouse',
            address: 'Testovaci 123',
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
            innerHeight: 200,
            innerLength: 150,
            defaultPricePerWeek: 10000,
            defaultPricePerMonth: 35000,
            createdAt: new \DateTimeImmutable(),
        );

        $storage = new Storage(
            id: Uuid::v7(),
            number: 'A1',
            coordinates: ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0],
            storageType: $storageType,
            place: $place,
            createdAt: new \DateTimeImmutable(),
        );

        $tenant = new User(
            Uuid::v7(),
            'tenant@example.com',
            'password',
            'Jan',
            'Novak',
            new \DateTimeImmutable(),
        );

        return new Order(
            id: $orderId,
            user: $tenant,
            storage: $storage,
            rentalType: RentalType::LIMITED,
            paymentFrequency: null,
            startDate: new \DateTimeImmutable('2025-06-20'),
            endDate: new \DateTimeImmutable('2025-07-20'),
            totalPrice: 35000,
            expiresAt: new \DateTimeImmutable('+7 days'),
            createdAt: new \DateTimeImmutable('2025-06-15'),
        );
    }
}
