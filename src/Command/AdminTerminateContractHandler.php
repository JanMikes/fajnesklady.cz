<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\TerminationReason;
use App\Event\ContractTerminated;
use App\Event\TerminationNoticeRequested;
use App\Service\AuditLogger;
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
    ) {
    }

    public function __invoke(AdminTerminateContractCommand $command): void
    {
        $contract = $command->contract;
        $now = $this->clock->now();

        if ($command->immediate) {
            $this->contractService->terminateContract($contract, $now, TerminationReason::ADMIN);

            $this->auditLogger->log(
                'contract',
                $contract->id->toRfc4122(),
                'admin_terminated_immediately',
                ['reason' => $command->reason],
                orderId: $contract->order->id,
                userIdContext: $contract->user->id,
            );

            $this->eventBus->dispatch(new ContractTerminated(
                contractId: $contract->id,
                occurredOn: $now,
            ));
        } else {
            $terminatesAt = $now->modify('+1 month');
            $contract->requestTermination($now, $terminatesAt);

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
