<?php

declare(strict_types=1);

namespace App\Command;

use App\Event\TerminationNoticeRequested;
use App\Service\AuditLogger;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class RequestTerminationNoticeHandler
{
    public function __construct(
        private ClockInterface $clock,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(RequestTerminationNoticeCommand $command): void
    {
        $contract = $command->contract;
        $now = $this->clock->now();

        $terminatesAt = $now->modify('+1 month');

        $contract->requestTermination($now, $terminatesAt);

        $this->auditLogger->log(
            'Contract',
            $contract->id->toRfc4122(),
            'termination_notice_requested',
            ['terminates_at' => $terminatesAt->format('Y-m-d')],
        );

        $this->eventBus->dispatch(new TerminationNoticeRequested(
            contractId: $contract->id,
            terminatesAt: $terminatesAt,
            occurredOn: $now,
        ));
    }
}
