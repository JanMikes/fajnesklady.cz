<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\Payment;
use App\Repository\ContractRepository;
use App\Repository\PaymentRepository;
use App\Service\Identity\ProvideIdentity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RecordPaymentOnRecurringChargeHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
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

        $this->paymentRepository->save($payment);
    }
}
