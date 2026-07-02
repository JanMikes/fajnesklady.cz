<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\ContractProlongation;
use App\Repository\ContractProlongationRepository;
use App\Repository\ContractRepository;
use App\Service\Identity\ProvideIdentity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PersistContractProlongationHandler
{
    public function __construct(
        private ContractProlongationRepository $repository,
        private ContractRepository $contractRepository,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(ContractProlonged $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);

        $this->repository->save(new ContractProlongation(
            id: $this->identityProvider->next(),
            contract: $contract,
            previousEndDate: $event->previousEndDate,
            newEndDate: $event->newEndDate,
            prolongedAt: $event->occurredOn,
            prolongedBy: $event->prolongedBy,
            billingModeAfter: $contract->billingMode->value,
            paymentMethodAfter: $contract->order->paymentMethod?->value,
        ));
    }
}
