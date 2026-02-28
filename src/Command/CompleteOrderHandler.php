<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Enum\PaymentFrequency;
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
        $order = $command->order;
        $contract = $this->orderService->completeOrder($order, $now);

        // Set up recurring payment if applicable
        if ($order->isUnlimited() && null !== $order->goPayParentPaymentId) {
            $nextBillingDate = $this->calculateNextBillingDate($now, $order->paymentFrequency);
            $contract->setRecurringPayment($order->goPayParentPaymentId, $nextBillingDate);
        }

        // Generate contract document and sign
        $this->contractService->generateDocument($contract, $order->signaturePath, $now);
        $this->contractService->signContract($contract, $now);

        return $contract;
    }

    private function calculateNextBillingDate(\DateTimeImmutable $now, ?PaymentFrequency $frequency): \DateTimeImmutable
    {
        return match ($frequency) {
            PaymentFrequency::YEARLY => $now->modify('+1 year'),
            default => $now->modify('+1 month'),
        };
    }
}
