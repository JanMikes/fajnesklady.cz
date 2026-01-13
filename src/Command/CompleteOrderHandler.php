<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Service\ContractService;
use App\Service\OrderService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CompleteOrderHandler
{
    public function __construct(
        private OrderService $orderService,
        private ContractService $contractService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CompleteOrderCommand $command): Contract
    {
        $now = $this->clock->now();
        $contract = $this->orderService->completeOrder($command->order, $now);

        // Generate contract document and sign
        $this->contractService->generateDocument($contract, $now);
        $this->contractService->signContract($contract, $now);

        return $contract;
    }
}
