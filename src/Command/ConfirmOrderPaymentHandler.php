<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\OrderService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ConfirmOrderPaymentHandler
{
    public function __construct(
        private OrderService $orderService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ConfirmOrderPaymentCommand $command): void
    {
        $this->orderService->confirmPayment($command->order, $this->clock->now());
    }
}
