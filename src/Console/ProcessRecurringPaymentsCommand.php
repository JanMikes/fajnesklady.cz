<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\ChargeRecurringPaymentCommand;
use App\Entity\Contract;
use App\Event\RecurringPaymentFailed;
use App\Repository\ContractRepository;
use App\Service\AuditLogger;
use App\Service\GoPay\GoPayException;
use App\Service\GoPay\PaymentNotConfirmedException;
use App\Service\Messenger\HandlerFailureUnwrap;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:process-recurring-payments',
    description: 'Process due recurring payments for unlimited contracts',
)]
final class ProcessRecurringPaymentsCommand extends Command
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly ManagerRegistry $doctrine,
        private readonly MessageBusInterface $commandBus,
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();

        $contracts = $this->contractRepository->findDueForBilling($now);

        if (0 === count($contracts)) {
            $io->info('No contracts due for billing.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d contracts due for billing.', count($contracts)));

        $successCount = 0;
        $failureCount = 0;

        foreach ($contracts as $contract) {
            try {
                $this->commandBus->dispatch(new ChargeRecurringPaymentCommand($contract));
                ++$successCount;
                $io->text(sprintf('  [OK] Contract %s charged successfully.', $contract->id));
            } catch (\Throwable $rawException) {
                ++$failureCount;
                $exception = HandlerFailureUnwrap::unwrap($rawException);

                $this->recordFailure($contract, $exception, $now);

                $io->error(sprintf('  [FAIL] Contract %s: %s', $contract->id, $exception->getMessage()));
            }
        }

        $io->success(sprintf('Processed: %d success, %d failures.', $successCount, $failureCount));

        return $failureCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Record a billing failure for a contract. Wrapped so any follow-up error
     * (closed EntityManager after a Doctrine rollback, event-bus failure, …)
     * cannot break the rest of the cron run — the unrecorded failure surfaces
     * again on the next cron run.
     */
    private function recordFailure(Contract $contract, \Throwable $exception, \DateTimeImmutable $now): void
    {
        $isExpectedFailure = $exception instanceof GoPayException
            || $exception instanceof PaymentNotConfirmedException;

        try {
            if ($isExpectedFailure) {
                $contract->recordFailedBillingAttempt($now);
                $this->getEntityManager()->flush();

                $this->auditLogger->log(
                    entityType: 'contract',
                    entityId: $contract->id->toRfc4122(),
                    eventType: 'recurring_payment_failed',
                    payload: [
                        'attempt' => $contract->failedBillingAttempts,
                        'reason' => $exception->getMessage(),
                    ],
                    orderId: $contract->order->id,
                    userIdContext: $contract->user->id,
                );

                $this->eventBus->dispatch(new RecurringPaymentFailed(
                    contractId: $contract->id,
                    attempt: $contract->failedBillingAttempts,
                    reason: $exception->getMessage(),
                    occurredOn: $now,
                ));

                $this->logger->error('Recurring payment processing failed', [
                    'contract_id' => $contract->id->toRfc4122(),
                    'attempt' => $contract->failedBillingAttempts,
                    'exception' => $exception,
                ]);
            } else {
                $this->logger->error('Recurring payment processing failed (unexpected)', [
                    'contract_id' => $contract->id->toRfc4122(),
                    'exception' => $exception,
                ]);
            }
        } catch (\Throwable $followUpException) {
            $this->logger->critical('Failed to record billing failure — resetting EntityManager and continuing', [
                'contract_id' => $contract->id->toRfc4122(),
                'original_exception' => $exception,
                'follow_up_exception' => $followUpException,
            ]);

            $this->doctrine->resetManager();
        }
    }

    private function getEntityManager(): EntityManagerInterface
    {
        $manager = $this->doctrine->getManager();
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Default Doctrine manager is not an ORM EntityManager.');
        }

        if (!$manager->isOpen()) {
            $this->doctrine->resetManager();
            $reset = $this->doctrine->getManager();
            \assert($reset instanceof EntityManagerInterface);

            return $reset;
        }

        return $manager;
    }
}
