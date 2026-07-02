<?php

declare(strict_types=1);

namespace App\Console;

use App\Entity\Contract;
use App\Enum\BillingMode;
use App\Enum\TerminationReason;
use App\Event\ContractTerminated;
use App\Repository\ContractRepository;
use App\Service\ContractService;
use Doctrine\DBAL\LockMode;
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
    name: 'app:process-contract-terminations',
    description: 'Terminate contracts that have reached their end date or termination notice date',
)]
final class ProcessContractTerminationsCommand extends Command
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly ContractService $contractService,
        private readonly ManagerRegistry $doctrine,
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();

        $contracts = $this->contractRepository->findDueForTermination($now);

        if (0 === count($contracts)) {
            $io->info('No contracts due for termination.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d contracts due for termination.', count($contracts)));

        $terminatedCount = 0;

        foreach ($contracts as $contract) {
            try {
                $entityManager = $this->getEntityManager();
                $terminated = false;

                // Explicit transaction: lock + re-validate right before
                // terminating — a customer's prolongation may have moved the
                // endDate between the candidate SELECT above and this point
                // (spec 077). Lock order (contract, then storage inside
                // terminate()) matches ProlongContractHandler, so the two
                // serialise instead of deadlocking. The transaction also
                // covers the flush that consoles must do themselves (no
                // doctrine_transaction middleware here).
                $entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($contract, $now, &$terminated): void {
                    $em->refresh($contract, LockMode::PESSIMISTIC_WRITE);

                    if (!$this->isStillDueForTermination($contract, $now)) {
                        return;
                    }

                    $reason = null !== $contract->terminatesAt
                        ? TerminationReason::TENANT_NOTICE  // User requested termination
                        : TerminationReason::EXPIRED;        // Contract reached endDate

                    $this->contractService->terminateContract($contract, $now, $reason);
                    $terminated = true;
                });

                if (!$terminated) {
                    $io->text(sprintf('  [SKIP] Contract %s no longer due (prolonged meanwhile).', $contract->id));

                    continue;
                }

                $this->eventBus->dispatch(new ContractTerminated(
                    contractId: $contract->id,
                    occurredOn: $now,
                ));

                ++$terminatedCount;
                $io->text(sprintf('  [OK] Contract %s terminated.', $contract->id));
            } catch (\Exception $e) {
                $this->logger->error('Contract termination failed', [
                    'contract_id' => $contract->id->toRfc4122(),
                    'exception' => $e,
                ]);
                $io->error(sprintf('  [ERROR] Contract %s: %s', $contract->id, $e->getMessage()));

                $this->doctrine->resetManager();
            }
        }

        $io->success(sprintf('Terminated %d contracts.', $terminatedCount));

        return Command::SUCCESS;
    }

    /**
     * PHP twin of {@see ContractRepository::findDueForTermination()}'s SQL —
     * re-evaluated under the row lock so a just-committed prolongation (moved
     * endDate, re-seeded nextBillingDate) takes the contract off the kill list.
     */
    private function isStillDueForTermination(Contract $contract, \DateTimeImmutable $now): bool
    {
        if ($contract->isTerminated()) {
            return false;
        }

        if (null !== $contract->terminatesAt && $contract->terminatesAt <= $now) {
            return true;
        }

        if ($contract->endDate > $now) {
            return false;
        }

        return match ($contract->billingMode) {
            BillingMode::ONE_TIME => true,
            BillingMode::AUTO_RECURRING => 0 === $contract->failedBillingAttempts
                && null === $contract->pendingRecurringPaymentId
                && null === $contract->nextBillingDate,
            BillingMode::MANUAL_RECURRING => null === $contract->nextBillingDate
                && 0 === $contract->failedBillingAttempts,
        };
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
