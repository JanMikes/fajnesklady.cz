<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Entity\Contract;
use App\Entity\Order;
use App\Entity\Place;
use App\Entity\Storage;
use App\Entity\StorageType;
use App\Entity\User;
use App\Enum\PaymentFrequency;
use App\Enum\TerminationReason;
use App\Repository\ContractRepository;
use App\Service\Order\OrderDisplayStatusResolver;
use App\Twig\OrderStatusBadgeExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Regression (order 01a0cd43): order lists rendered OrderStatus::COMPLETED as
 * "Dokončeno", so every running rental looked finished.
 */
class OrderStatusBadgeExtensionTest extends TestCase
{
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2025-06-15 12:00:00');
    }

    public function testCompletedOrderWithRunningContractReadsActive(): void
    {
        [$order, $contract] = $this->completedOrderWithContract();

        $badge = $this->extension()->badge($order, $contract);

        self::assertSame('Aktivní', $badge['label']);
        self::assertSame('green', $badge['tone']);
    }

    public function testCompletedOrderLooksUpContractWhenNotPassed(): void
    {
        [$order, $contract] = $this->completedOrderWithContract();
        $contract->terminate($this->now, TerminationReason::EXPIRED, releaseStorage: false);

        $repository = $this->createMock(ContractRepository::class);
        $repository->expects(self::once())->method('findByOrder')->with($order)->willReturn($contract);

        $badge = (new OrderStatusBadgeExtension($repository, new OrderDisplayStatusResolver()))->badge($order);

        self::assertSame('Dokončeno', $badge['label'], 'Only a contract that actually ended reads "Dokončeno".');
        self::assertSame('gray', $badge['tone']);
        self::assertStringContainsString('15.06.2025', (string) $badge['title']);
    }

    public function testPreCompletionStatusKeepsGranularLabel(): void
    {
        $order = $this->createOrder();
        $order->reserve($this->now);

        $badge = $this->extension()->badge($order);

        self::assertSame('Rezervováno', $badge['label']);
        self::assertSame('yellow', $badge['tone']);
    }

    private function extension(): OrderStatusBadgeExtension
    {
        $repository = $this->createMock(ContractRepository::class);
        $repository->expects(self::never())->method('findByOrder');

        return new OrderStatusBadgeExtension($repository, new OrderDisplayStatusResolver());
    }

    /**
     * @return array{Order, Contract}
     */
    private function completedOrderWithContract(): array
    {
        $order = $this->createOrder();
        $order->reserve($this->now);
        $order->markPaid($this->now);
        $contractId = Uuid::v7();
        $order->complete($contractId, $this->now);

        $contract = new Contract(
            id: $contractId,
            order: $order,
            user: $order->user,
            storage: $order->storage,
            startDate: $order->startDate,
            endDate: $order->startDate->modify('+1 month'),
            createdAt: $this->now,
        );

        return [$order, $contract];
    }

    private function createOrder(): Order
    {
        $user = new User(Uuid::v7(), 'test@example.com', 'pw', 'Jan', 'Novák', $this->now);
        $place = new Place(Uuid::v7(), 'Pobočka', 'Adresa 1', 'Praha', '110 00', null, $this->now);
        $storageType = new StorageType(Uuid::v7(), $place, 'Box', 100, 100, 100, 10000, 35000, 35000, 35000 * 12, $this->now);
        $storage = new Storage(Uuid::v7(), 'A1', ['x' => 0, 'y' => 0, 'width' => 100, 'height' => 100, 'rotation' => 0, 'normalized' => true], $storageType, $place, $this->now);

        return new Order(
            id: Uuid::v7(),
            user: $user,
            storage: $storage,
            paymentFrequency: PaymentFrequency::MONTHLY,
            startDate: $this->now,
            endDate: $this->now->modify('+1 month'),
            firstPaymentPrice: 35000,
            expiresAt: $this->now->modify('+7 days'),
            createdAt: $this->now,
        );
    }
}
