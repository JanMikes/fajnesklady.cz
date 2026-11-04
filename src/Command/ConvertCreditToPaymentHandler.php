<?php

declare(strict_types=1);

namespace App\Command;

use App\Event\RecurringPaymentCharged;
use App\Repository\PaymentRepository;
use App\Service\AuditLogger;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Order 01a051d7: a 1 900 Kč transfer paid a cycle requested (wrongly prorated)
 * at 1 837 Kč; 63 Kč sat as credit on a contract that then ended. Converting
 * it records the 63 Kč as part of that transfer's payment for the same cycle
 * and invoices it, so "Zaplaceno" matches the money received.
 *
 * Reuses the bank-transfer cycle path (RecurringPaymentCharged::viaBankTransfer):
 * the Payment row (linked to the transfer), the paid bank-transfer invoice and
 * the invoice e-mail are produced by the same handlers as any FIO payment.
 */
#[AsMessageHandler]
final readonly class ConvertCreditToPaymentHandler
{
    public function __construct(
        private PaymentRepository $paymentRepository,
        private AuditLogger $auditLogger,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(ConvertCreditToPaymentCommand $command): void
    {
        $contract = $command->contract;
        $bankTransaction = $command->bankTransaction;

        if ($contract->creditBalance <= 0) {
            throw new \DomainException('Contract has no credit to convert.');
        }

        if (null === $bankTransaction->pairedOrder || !$bankTransaction->pairedOrder->id->equals($contract->order->id)) {
            throw new \DomainException('The bank transaction is not paired to this contract\'s order — it cannot evidence the payment.');
        }

        // The credit is the transfer's surplus, so it pays the same cycle the
        // transfer paid — invoiced with that period, not as a loose amount.
        $periodStart = null;
        $periodEnd = null;
        foreach ($this->paymentRepository->findByBankTransaction($bankTransaction) as $payment) {
            if (null !== $payment->periodStart && null !== $payment->periodEnd) {
                $periodStart = $payment->periodStart;
                $periodEnd = $payment->periodEnd;
            }
        }

        if (null === $periodStart || null === $periodEnd) {
            throw new \DomainException('No billing cycle paid by this transfer — cannot tell what the credit pays for.');
        }

        $now = $this->clock->now();
        $amount = $contract->consumeCredit($contract->creditBalance);

        $this->auditLogger->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'credit_converted_to_payment',
            payload: [
                'amount' => $amount,
                'bank_transaction_id' => $bankTransaction->id->toRfc4122(),
                'period_start' => $periodStart->format('Y-m-d'),
                'period_end' => $periodEnd->format('Y-m-d'),
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );

        $this->eventBus->dispatch(RecurringPaymentCharged::viaBankTransfer(
            contractId: $contract->id,
            bankTransactionId: $bankTransaction->id,
            amount: $amount,
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            occurredOn: $now,
        ));
    }
}
