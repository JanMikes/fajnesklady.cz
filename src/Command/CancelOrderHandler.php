<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\OrderService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CancelOrderHandler
{
    public function __construct(
        private OrderService $orderService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CancelOrderCommand $command): void
    {
        $this->orderService->cancelOrder($command->order, $this->clock->now());
    }
}
