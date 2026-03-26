<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\HandoverProtocol;
use App\Event\HandoverProtocolCreated;
use App\Repository\ContractRepository;
use App\Repository\HandoverProtocolRepository;
use App\Service\Identity\ProvideIdentity;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class CreateHandoverProtocolHandler
{
    public function __construct(
        private ContractRepository $contractRepository,
        private HandoverProtocolRepository $handoverProtocolRepository,
        private ProvideIdentity $identityProvider,
        private ClockInterface $clock,
        #[Autowire(service: 'event.bus')]
        private MessageBusInterface $eventBus,
    ) {
    }

    public function __invoke(CreateHandoverProtocolCommand $command): HandoverProtocol
    {
        $contract = $this->contractRepository->get($command->contractId);

        $existing = $this->handoverProtocolRepository->findByContract($contract);
        if (null !== $existing) {
            return $existing;
        }

        $now = $this->clock->now();

        $handoverProtocol = new HandoverProtocol(
            id: $this->identityProvider->next(),
            contract: $contract,
            createdAt: $now,
        );

        $this->handoverProtocolRepository->save($handoverProtocol);

        $this->eventBus->dispatch(new HandoverProtocolCreated(
            handoverProtocolId: $handoverProtocol->id,
            contractId: $contract->id,
            occurredOn: $now,
        ));

        return $handoverProtocol;
    }
}
