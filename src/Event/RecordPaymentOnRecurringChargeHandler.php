<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Payment;
use App\Repository\BankTransactionRepository;
use App\Repository\ContractRepository;
use App\Repository\PaymentRepository;
use App\Service\Identity\ProvideIdentity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecordPaymentOnRecurringChargeHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private BankTransactionRepository $bankTransactionRepository,
        private PaymentRepository $paymentRepository,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(RecurringPaymentCharged $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);

        if (null !== $this->paymentRepository->findByContractAndPaidAt($contract, $event->occurredOn)) {
            return;
        }

        $payment = new Payment(
            id: $this->identityProvider->next(),
            order: null,
            contract: $contract,
            storage: $contract->storage,
            amount: $event->amount,
            paidAt: $event->occurredOn,
            createdAt: $event->occurredOn,
        );

        // Only a real GoPay id may land in goPayPaymentId — its unique index is
        // the race backstop for parallel webhook deliveries, and the payment
        // overview labels any non-null value "Kartou (GoPay)".
        if (null !== $event->goPayPaymentId) {
            $payment->setGoPayPaymentId($event->goPayPaymentId);
        }

        if (null !== $event->bankTransactionId) {
            $bankTransaction = $this->bankTransactionRepository->find($event->bankTransactionId);
            if (null !== $bankTransaction) {
                $payment->linkBankTransaction($bankTransaction);
            }
        }

        $payment->coverPeriod($event->periodStart, $event->periodEnd);

        $this->paymentRepository->save($payment);
    }
}
