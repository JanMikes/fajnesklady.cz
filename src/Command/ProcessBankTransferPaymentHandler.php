<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\BillingMode;
use App\Event\RecurringPaymentCharged;
use App\Repository\ContractRepository;
use App\Repository\ManualPaymentRequestRepository;
use App\Service\AuditLogger;
use App\Service\OrderService;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class ProcessBankTransferPaymentHandler
{
    public function __construct(
        private OrderService $orderService,
        private ContractRepository $contractRepository,
        private ManualPaymentRequestRepository $manualPaymentRequestRepository,
        private AuditLogger $auditLogger,
        private MessageBusInterface $commandBus,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ProcessBankTransferPaymentCommand $command): void
    {
        $transaction = $command->transaction;
        $order = $command->order;
        $effectiveAmount = $command->totalAmount ?? $transaction->amount;
        $now = $this->clock->now();

        $contract = $this->contractRepository->findByOrder($order);

        if (null !== $contract && BillingMode::MANUAL_RECURRING === $contract->billingMode) {
            $this->reconcileBankTransferRecurring($transaction, $contract, $effectiveAmount, $now);

            return;
        }

        if ($order->canBePaid()) {
            $this->orderService->confirmPayment($order, $now, $effectiveAmount);

            if ($order->hasAcceptedTerms()) {
                $this->commandBus->dispatch(new CompleteOrderCommand($order));
            }

            $this->auditLogger->log(
                entityType: 'order',
                entityId: $order->id->toRfc4122(),
                eventType: 'bank_transfer_payment_confirmed',
                payload: [
                    'bank_transaction_id' => $transaction->id->toRfc4122(),
                    'fio_transaction_id' => $transaction->fioTransactionId,
                    'amount' => $effectiveAmount,
                    'transaction_amount' => $transaction->amount,
                    'accumulated' => null !== $command->totalAmount,
                    'variable_symbol' => $transaction->variableSymbol,
                    'sender_account' => $transaction->senderAccountNumber,
                ],
                orderId: $order->id,
                userIdContext: $order->user->id,
            );

            return;
        }

        $this->logger->info('Bank transfer payment received but order cannot be paid', [
            'order_id' => $order->id->toRfc4122(),
            'bank_transaction_id' => $transaction->id->toRfc4122(),
            'order_status' => $order->status->value,
        ]);
    }

    private function reconcileBankTransferRecurring(
        \App\Entity\BankTransaction $transaction,
        \App\Entity\Contract $contract,
        int $effectiveAmount,
        \DateTimeImmutable $now,
    ): void {
        $billingPeriodStart = $contract->nextBillingDate ?? $now;
        $effectiveEndDate = $contract->getEffectiveEndDate();
        $nextBillingDate = $billingPeriodStart->modify($contract->getBillingCadenceStep());
        $paidThroughDate = $nextBillingDate;

        if ($nextBillingDate >= $effectiveEndDate) {
            $nextBillingDate = null;
            $paidThroughDate = $effectiveEndDate;
        }

        $contract->recordBillingCharge($now, $nextBillingDate, $paidThroughDate);

        $manualRequest = $this->manualPaymentRequestRepository->findUnpaidByContractAndPeriod(
            $contract,
            $billingPeriodStart,
        );
        if (null !== $manualRequest) {
            $manualRequest->markPaid($now);
        }

        $this->eventBus->dispatch(new RecurringPaymentCharged(
            contractId: $contract->id,
            paymentId: $transaction->id->toRfc4122(),
            amount: $effectiveAmount,
            occurredOn: $now,
        ));

        $this->auditLogger->log(
            entityType: 'contract',
            entityId: $contract->id->toRfc4122(),
            eventType: 'bank_transfer_recurring_confirmed',
            payload: [
                'bank_transaction_id' => $transaction->id->toRfc4122(),
                'fio_transaction_id' => $transaction->fioTransactionId,
                'amount' => $transaction->amount,
                'variable_symbol' => $transaction->variableSymbol,
                'billing_period_start' => $billingPeriodStart->format('Y-m-d'),
                'sender_account' => $transaction->senderAccountNumber,
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }
}
