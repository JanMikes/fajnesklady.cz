<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\TerminationReason;
use App\Event\ContractTerminated;
use App\Event\TerminationNoticeRequested;
use App\Repository\ManualPaymentRequestRepository;
use App\Service\AuditLogger;
use App\Service\Billing\BillingCalendar;
use App\Service\ContractService;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class AdminTerminateContractHandler
{
    public function __construct(
        private ContractService $contractService,
        private ClockInterface $clock,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private AuditLogger $auditLogger,
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
    ) {
    }

    public function __invoke(AdminTerminateContractCommand $command): void
    {
        $contract = $command->contract;
        $now = $this->clock->now();

        if ($command->immediate) {
            // Record the unpaid period as a debt BEFORE terminating so the
            // existing ContractTerminated e-mail (which reloads the contract)
            // surfaces the amount, and the contract stays in Po splatnosti.
            $recordedDebt = 0;
            if ($command->recordDebt) {
                $recordedDebt = $this->contractService->calculateOutstandingDebt($contract, $now);
                if ($recordedDebt > 0) {
                    $contract->setOutstandingDebt($recordedDebt);
                }
            }

            $this->contractService->terminateContract($contract, $now, TerminationReason::ADMIN);

            $this->auditLogger->log(
                'contract',
                $contract->id->toRfc4122(),
                'admin_terminated_immediately',
                ['reason' => $command->reason, 'recorded_debt' => $recordedDebt],
                orderId: $contract->order->id,
                userIdContext: $contract->user->id,
            );

            $this->eventBus->dispatch(new ContractTerminated(
                contractId: $contract->id,
                occurredOn: $now,
            ));
        } else {
            // One-month notice; a missing day ends on the month's last day (OZ § 605 odst. 2).
            $terminatesAt = BillingCalendar::addMonths($now, 1);
            $dueBefore = $contract->nextBillingDate;
            $contract->requestTermination($now, $terminatesAt);

            // See RequestTerminationNoticeHandler: a pending request keyed on a
            // due date the notice moved/dropped must not keep chasing.
            if (null !== $dueBefore && $dueBefore->format('Y-m-d') !== $contract->nextBillingDate?->format('Y-m-d')) {
                $this->manualPaymentRequestRepository->findUnpaidByContractAndPeriod($contract, $dueBefore)?->cancel();
            }

            $this->auditLogger->log(
                'contract',
                $contract->id->toRfc4122(),
                'admin_termination_notice',
                ['terminates_at' => $terminatesAt->format('Y-m-d'), 'reason' => $command->reason],
                orderId: $contract->order->id,
                userIdContext: $contract->user->id,
            );

            $this->eventBus->dispatch(new TerminationNoticeRequested(
                contractId: $contract->id,
                terminatesAt: $terminatesAt,
                occurredOn: $now,
            ));
        }
    }
}
