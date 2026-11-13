<?php

declare(strict_types=1);

namespace App\Command;

use App\Event\RecurringPaymentCancelled;
use App\Service\AuditLogger;
use App\Service\Billing\CardToBankTransferSwitch;
use App\Service\GoPay\GoPayClient;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class CancelRecurringPaymentHandler
{
    public function __construct(
        private GoPayClient $goPayClient,
        private CardToBankTransferSwitch $cardToBankTransferSwitch,
        private ClockInterface $clock,
        private MessageBusInterface $eventBus,
        private AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(CancelRecurringPaymentCommand $command): void
    {
        $contract = $command->contract;
        $now = $this->clock->now();

        // Podmínky opakovaných plateb čl. VI: stopping card payments does not
        // end the lease nor the duty to pay for it. While the contract still
        // has unpaid time ahead (a billing anchor), keep that anchor and move
        // billing to bank transfer — the manual-billing cron then requests the
        // cycle and the overdue sweep (VOP čl. XI) still applies. Clearing the
        // anchor here used to drop the contract out of billing, reminders and
        // termination alike (2026-11-12, contract 019fec6a).
        $nextBillingDate = $contract->nextBillingDate;

        if (null !== $nextBillingDate && !$contract->isTerminated() && !$contract->isFree()) {
            $this->cardToBankTransferSwitch->switch($contract, $nextBillingDate);
        } else {
            if ($contract->hasActiveRecurringPayment()) {
                /** @var string $parentPaymentId */
                $parentPaymentId = $contract->goPayParentPaymentId;
                $this->goPayClient->voidRecurrence($parentPaymentId);
            }

            $contract->cancelRecurringPayment();
        }

        // Audit here (not at dispatch sites) so the command bus's flush covers
        // the row and every cancellation path — tenant portal, signed e-mail
        // link, payment-default cron — gets the same trail.
        $this->auditLogger->logContractRecurringCancelled($contract);

        $this->eventBus->dispatch(new RecurringPaymentCancelled(
            contractId: $contract->id,
            occurredOn: $now,
        ));
    }
}
