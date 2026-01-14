<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\OrderRepository;
use App\Service\InvoicingService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class IssueInvoiceOnPaymentHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private InvoicingService $invoicingService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(OrderPaid $event): void
    {
        $order = $this->orderRepository->get($event->orderId);
        $this->invoicingService->issueInvoiceForOrder($order, $this->clock->now());
    }
}
