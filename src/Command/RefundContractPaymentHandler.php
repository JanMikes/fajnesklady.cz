<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PaymentRefund;
use App\Enum\RefundMode;
use App\Exception\RefundNotPossible;
use App\Repository\ContractRepository;
use App\Repository\UserRepository;
use App\Service\Refund\PaymentRefundService;
use App\Service\Refund\RefundablePaymentResolver;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RefundContractPaymentHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private UserRepository $userRepository,
        private RefundablePaymentResolver $resolver,
        private PaymentRefundService $refundService,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RefundContractPaymentCommand $command): PaymentRefund
    {
        if (RefundMode::EXTERNAL === $command->mode) {
            throw new RefundNotPossible('Neplatný způsob vrácení.');
        }

        $contract = $this->contractRepository->get($command->contractId);

        // Serialise concurrent submits on the contract, then re-resolve under
        // the lock: a second submit finds the payment already (being) refunded.
        $this->contractRepository->lockForUpdate($contract);

        if (!$contract->isTerminated()) {
            throw new RefundNotPossible('Platbu lze vrátit jen u ukončené smlouvy.');
        }

        $refundable = $this->resolver->resolve($contract);
        if (null === $refundable) {
            throw new RefundNotPossible('Smlouva nemá žádnou platbu kartou k vrácení (nebo už byla vrácena).');
        }

        if (!$refundable->payment->id->equals($command->paymentId)) {
            throw new RefundNotPossible('Platba k vrácení se mezitím změnila — načtěte stránku znovu.');
        }

        return $this->refundService->refund(
            contract: $contract,
            refundable: $refundable,
            mode: $command->mode,
            refundFrom: $command->refundFrom,
            issueCreditNote: $command->issueCreditNote,
            reason: $command->reason,
            admin: $this->userRepository->get($command->adminId),
            now: $this->clock->now(),
        );
    }
}
