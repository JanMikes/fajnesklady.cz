<?php

declare(strict_types=1);

namespace App\Event;

use App\Entity\ContractPriceChange;
use App\Repository\ContractPriceChangeRepository;
use App\Repository\ContractRepository;
use App\Service\Identity\ProvideIdentity;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class PersistContractPriceChangeHandler
{
    public function __construct(
        private ContractPriceChangeRepository $repository,
        private ContractRepository $contractRepository,
        private ProvideIdentity $identityProvider,
    ) {
    }

    public function __invoke(ContractPriceChanged $event): void
    {
        $contract = $this->contractRepository->get($event->contractId);

        $this->repository->save(new ContractPriceChange(
            id: $this->identityProvider->next(),
            contract: $contract,
            previousAmount: $event->previousAmount,
            newAmount: $event->newAmount,
            changedAt: $event->occurredOn,
            changedBy: $event->changedBy,
            reason: $event->reason,
        ));
    }
}
