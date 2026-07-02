<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\BankTransaction;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class UnignoreBankTransactionHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
    ) {
    }

    public function __invoke(UnignoreBankTransactionCommand $command): void
    {
        $transaction = $this->entityManager->find(BankTransaction::class, $command->transactionId);
        if (null === $transaction) {
            throw new \DomainException('Bank transaction not found.');
        }

        if (!$transaction->isIgnored()) {
            throw new \DomainException('Only ignored transactions can be unignored.');
        }

        // Capture before unignore() — it nulls the field.
        $previousReason = $transaction->ignoreReason;

        $transaction->unignore();

        $this->auditLogger->log(
            entityType: 'bank_transaction',
            entityId: $command->transactionId->toRfc4122(),
            eventType: 'unignored',
            payload: [
                'fio_transaction_id' => $transaction->fioTransactionId,
                'amount' => $transaction->amount,
                'variable_symbol' => $transaction->variableSymbol,
                'reason' => $previousReason,
                'unignored_by' => $command->adminId->toRfc4122(),
            ],
        );
    }
}
