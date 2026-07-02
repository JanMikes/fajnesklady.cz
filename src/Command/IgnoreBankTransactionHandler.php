<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BankTransaction;
use App\Entity\User;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class IgnoreBankTransactionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(IgnoreBankTransactionCommand $command): void
    {
        $transaction = $this->entityManager->find(BankTransaction::class, $command->transactionId);
        if (null === $transaction) {
            throw new \DomainException('Bank transaction not found.');
        }

        $admin = $this->entityManager->find(User::class, $command->adminId);
        if (null === $admin) {
            throw new \DomainException('Admin user not found.');
        }

        if (!$transaction->isUnmatched()) {
            throw new \DomainException('Only unmatched transactions can be ignored.');
        }

        $transaction->markIgnored($admin, $command->reason, $this->clock->now());

        $this->auditLogger->log(
            entityType: 'bank_transaction',
            entityId: $command->transactionId->toRfc4122(),
            eventType: 'ignored',
            payload: [
                'fio_transaction_id' => $transaction->fioTransactionId,
                'amount' => $transaction->amount,
                'variable_symbol' => $transaction->variableSymbol,
                'reason' => $command->reason,
            ],
        );
    }
}
