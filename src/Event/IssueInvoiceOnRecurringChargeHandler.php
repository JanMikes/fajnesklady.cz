<?php

declare(strict_types=1);

namespace App\Event;

use App\Repository\ContractRepository;
use App\Service\InvoicingService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class IssueInvoiceOnRecurringChargeHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private InvoicingService $invoicingService,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecurringPaymentCharged $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);

        try {
            $this->invoicingService->issueInvoiceForRecurringPayment(
                $contract,
                $event->amount,
                $event->occurredOn,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to issue invoice for recurring payment', [
                'contract_id' => $contract->id->toRfc4122(),
                'amount' => $event->amount,
                'exception' => $e,
            ]);
        }
    }
}
