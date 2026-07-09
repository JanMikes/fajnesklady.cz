<?php

declare(strict_types=1);

namespace App\Console;

use App\Entity\Contract;
use App\Enum\TerminationReason;
use App\Event\ContractTerminatedDueToPaymentFailure;
use App\Repository\ContractRepository;
use App\Repository\PlatformSettingsRepository;
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

/**
 * The single termination authority for non-payment (VOP čl. XI): every active
 * non-free contract whose nextBillingDate is more than the platform-configured
 * number of days in the past is terminated without notice — AUTO (failed card
 * charges), MANUAL (unpaid bank-transfer cycle), yearly, and lapsed externally
 * prepaid alike. The card retry cron only retries; it no longer terminates.
 */
#[AsCommand(
    name: 'app:terminate-overdue-contracts',
    description: 'Terminate contracts overdue past the platform-configured limit (VOP čl. XI)',
)]
final class TerminateOverdueContractsCommand extends Command
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly ContractService $contractService,
        private readonly PlatformSettingsRepository $settingsRepository,
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

        $days = $this->settingsRepository->getSettings()->overdueTerminationDays;
        $overdueSince = $now->modify(sprintf('-%d days', $days));

        $contracts = array_filter(
            $this->contractRepository->findOverdueForTermination($overdueSince),
            static fn (Contract $c): bool => !$c->isFree(),
        );

        if (0 === count($contracts)) {
            $io->info(sprintf('No contracts overdue more than %d days.', $days));

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d contracts overdue more than %d days.', count($contracts), $days));

        $terminatedCount = 0;

        foreach ($contracts as $contract) {
            try {
                $entityManager = $this->getEntityManager();
                $terminated = false;
                $outstandingDebt = 0;
                $daysOverdue = 0;

                // Explicit transaction: lock + re-validate right before
                // terminating — a payment recorded by the GoPay webhook or the
                // FIO cron between the candidate SELECT above and this point
                // advances nextBillingDate and takes the contract off the kill
                // list. refresh() also fails loudly on an entity detached by a
                // previous iteration's resetManager() instead of silently
                // mutating state that would never be flushed. The transaction
                // also covers the flush that consoles must do themselves (no
                // doctrine_transaction middleware here).
                $entityManager->wrapInTransaction(function (EntityManagerInterface $em) use ($contract, $now, $overdueSince, &$terminated, &$outstandingDebt, &$daysOverdue): void {
                    $em->refresh($contract, LockMode::PESSIMISTIC_WRITE);

                    // Captured before terminateContract() — voiding a live
                    // token clears nextBillingDate via cancelRecurringPayment().
                    // effectiveDunningAnchor honours an admin extension (spec
                    // 086): a still-active grace makes $dueDate future and bails.
                    $dueDate = $contract->effectiveDunningAnchor();

                    if ($contract->isTerminated() || $contract->isFree() || null === $dueDate || $dueDate > $overdueSince) {
                        return;
                    }

                    $daysOverdue = (int) $dueDate->diff($now)->days;

                    $outstandingDebt = $this->contractService->calculateOutstandingDebt($contract, $now);
                    if ($outstandingDebt > 0) {
                        $contract->setOutstandingDebt($outstandingDebt);
                    }

                    // Voids a live GoPay token, releases storage (handover-aware), audit-logs.
                    $this->contractService->terminateContract($contract, $now, TerminationReason::PAYMENT_FAILURE);
                    $terminated = true;
                });

                if (!$terminated) {
                    $io->text(sprintf('  [SKIP] Contract %s no longer eligible (payment received meanwhile).', $contract->id));

                    continue;
                }

                $this->eventBus->dispatch(new ContractTerminatedDueToPaymentFailure(
                    contractId: $contract->id,
                    outstandingDebtAmount: $outstandingDebt,
                    occurredOn: $now,
                ));

                ++$terminatedCount;

                $io->text(sprintf(
                    '  [OK] Contract %s terminated, %d dní po splatnosti, dluh %s Kč',
                    $contract->id,
                    $daysOverdue,
                    number_format($outstandingDebt / 100, 2, ',', ' '),
                ));
            } catch (\Throwable $e) {
                $this->logger->error('Overdue contract termination failed', [
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
