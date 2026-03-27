<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\AuditLogger;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class AcceptOrderTermsHandler
{
    public function __construct(
        private ClockInterface $clock,
        private AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(AcceptOrderTermsCommand $command): void
    {
        $now = $this->clock->now();
        $order = $command->order;

        $order->acceptTerms($now);

        if ($command->earlyStartWaiverAccepted) {
            $order->acceptEarlyStartWaiver($now);
        }

        $order->reserve($now);

        $this->auditLogger->logOrderReserved($order);
        $this->auditLogger->logStorageReserved($order->storage, $order);
    }
}
