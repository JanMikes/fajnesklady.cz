<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Invoice;
use App\Service\InvoicingService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class IssueInvoiceForOrderHandler
{
    public function __construct(
        private InvoicingService $invoicingService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(IssueInvoiceForOrderCommand $command): Invoice
    {
        return $this->invoicingService->issueInvoiceForOrder($command->order, $this->clock->now());
    }
}
