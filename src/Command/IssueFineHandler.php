<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Contract;
use App\Entity\Fine;
use App\Entity\User;
use App\Repository\FineRepository;
use App\Service\AuditLogger;
use App\Service\Identity\ProvideIdentity;
use App\Service\Payment\VariableSymbolGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class IssueFineHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private FineRepository $fineRepository,
        private AuditLogger $auditLogger,
        private ProvideIdentity $identityProvider,
        private VariableSymbolGenerator $variableSymbolGenerator,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(IssueFineCommand $command): Fine
    {
        $contract = $this->entityManager->find(Contract::class, $command->contractId);
        if (null === $contract) {
            throw new \DomainException('Contract not found.');
        }

        $admin = $this->entityManager->find(User::class, $command->issuedById);
        if (null === $admin) {
            throw new \DomainException('Admin user not found.');
        }

        $user = $contract->user;
        $now = $this->clock->now();
        $fineId = $this->identityProvider->next();

        $fine = new Fine(
            id: $fineId,
            contract: $contract,
            user: $user,
            issuedBy: $admin,
            type: $command->type,
            amountInHaler: $command->amountInHaler,
            description: $command->description,
            issuedAt: $now,
            createdAt: $now,
        );

        $vs = $this->variableSymbolGenerator->generateForFine($fineId);
        $fine->assignVariableSymbol($vs);

        $this->fineRepository->save($fine);

        $this->auditLogger->log(
            entityType: 'fine',
            entityId: $fineId->toRfc4122(),
            eventType: 'issued',
            payload: [
                'contract_id' => $contract->id->toRfc4122(),
                'type' => $command->type->value,
                'amount' => $command->amountInHaler,
                'issued_by' => $admin->id->toRfc4122(),
            ],
            orderId: $contract->order->id,
            userIdContext: $user->id,
        );

        return $fine;
    }
}
