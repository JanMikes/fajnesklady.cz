<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Service\InvoicingService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class IssueInvoiceOnPaymentHandler
{
    public function __construct(
        private OrderRepository $orderRepository,
        private InvoiceRepository $invoiceRepository,
        private InvoicingService $invoicingService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(OrderPaid $event): void
    {
        $order = $this->orderRepository->get($event->orderId);

        if (null !== $this->invoiceRepository->findByOrder($order)) {
            return;
        }

        $this->invoicingService->issueInvoiceForOrder($order, $this->clock->now());
    }
}
