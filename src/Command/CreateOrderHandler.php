<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Order;
use App\Service\OrderService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CreateOrderHandler
{
    public function __construct(
        private OrderService $orderService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateOrderCommand $command): Order
    {
        return $this->orderService->createOrder(
            user: $command->user,
            storageType: $command->storageType,
            rentalType: $command->rentalType,
            startDate: $command->startDate,
            endDate: $command->endDate,
            paymentFrequency: $command->paymentFrequency,
            now: $this->clock->now(),
        );
    }
}
