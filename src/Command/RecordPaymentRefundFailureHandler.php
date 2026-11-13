<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\ContractRepository;
use App\Repository\PaymentRepository;
use App\Service\AuditLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecordPaymentRefundFailureHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private PaymentRepository $paymentRepository,
        private AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(RecordPaymentRefundFailureCommand $command): void
    {
        $contract = $this->contractRepository->get($command->contractId);
        $payment = $this->paymentRepository->find($command->paymentId);

        $this->auditLogger->log(
            entityType: 'payment_refund',
            entityId: $command->paymentId->toRfc4122(),
            eventType: 'request_failed',
            payload: [
                'amount' => $command->amount,
                'payment_amount' => $payment?->amount,
                'gopay_payment_id' => $payment?->goPayPaymentId,
                'failure_reason' => $command->failureReason,
                'reason' => $command->reason,
                'contract_id' => $contract->id->toRfc4122(),
            ],
            orderId: $contract->order->id,
            userIdContext: $contract->user->id,
        );
    }
}
