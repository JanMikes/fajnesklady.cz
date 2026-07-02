<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\OrderService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExpireOrderHandler
{
    public function __construct(
        private OrderService $orderService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ExpireOrderCommand $command): void
    {
        $this->orderService->expireOrder($command->order, $this->clock->now());
    }
}
