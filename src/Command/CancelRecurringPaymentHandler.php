<?php

declare(strict_types=1);

namespace App\Command;

use App\Event\RecurringPaymentCancelled;
use App\Service\GoPay\GoPayClient;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class CancelRecurringPaymentHandler
{
    public function __construct(
        private GoPayClient $goPayClient,
        private ClockInterface $clock,
        private MessageBusInterface $eventBus,
    ) {
    }

    public function __invoke(CancelRecurringPaymentCommand $command): void
    {
        $contract = $command->contract;
        $now = $this->clock->now();

        if (!$contract->hasActiveRecurringPayment()) {
            return; // Nothing to cancel
        }

        /** @var string $parentPaymentId */
        $parentPaymentId = $contract->goPayParentPaymentId;

        // Cancel in GoPay
        $this->goPayClient->voidRecurrence($parentPaymentId);

        // Update contract
        $contract->cancelRecurringPayment();

        $this->eventBus->dispatch(new RecurringPaymentCancelled(
            contractId: $contract->id,
            occurredOn: $now,
        ));
    }
}
