<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\DispatchManualBillingNotificationCommand;
use App\Repository\ContractRepository;
use App\Service\Billing\ManualBillingReminderSchedule;
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
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:send-manual-billing-payment-requests',
    description: 'Send per-cycle payment-request and overdue reminder e-mails for MANUAL_RECURRING contracts',
)]
final class SendManualBillingPaymentRequestsCommand extends Command
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();

        $contracts = $this->contractRepository->findManualBillingCandidates($now);

        if (0 === count($contracts)) {
            $io->info('No MANUAL_RECURRING contracts to process.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Inspecting %d MANUAL_RECURRING contracts.', count($contracts)));

        $dispatched = 0;
        $failures = 0;

        foreach ($contracts as $contract) {
            try {
                if (null === $contract->nextBillingDate) {
                    continue;
                }

                $schedule = ManualBillingReminderSchedule::fromOrder($contract->order);
                $stage = $schedule->dueStageOn($now, $contract->nextBillingDate);

                if (null === $stage) {
                    continue;
                }

                $this->commandBus->dispatch(new DispatchManualBillingNotificationCommand(
                    contractId: $contract->id,
                    periodStart: $contract->nextBillingDate,
                    stage: $stage,
                ));
                ++$dispatched;
                $io->text(sprintf(
                    '  [OK] Contract %s — stage %s dispatched.',
                    $contract->id,
                    $stage,
                ));
            } catch (\Throwable $rawException) {
                ++$failures;
                $exception = HandlerFailureUnwrap::unwrap($rawException);

                $this->logger->error('Failed to dispatch manual-billing notification', [
                    'contract_id' => $contract->id->toRfc4122(),
                    'exception' => $exception,
                ]);
                $io->error(sprintf('  [FAIL] Contract %s: %s', $contract->id, $exception->getMessage()));

                $manager = $this->doctrine->getManager();
                if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
                    $this->doctrine->resetManager();
                }
            }
        }

        $io->success(sprintf('Dispatched: %d, failures: %d.', $dispatched, $failures));

        return $failures > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
