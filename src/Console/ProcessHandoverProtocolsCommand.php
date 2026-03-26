<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\CreateHandoverProtocolCommand;
use App\Event\HandoverExpired;
use App\Event\HandoverReminderDue;
use App\Repository\ContractRepository;
use App\Repository\HandoverProtocolRepository;
use Doctrine\ORM\EntityManagerInterface;
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
    name: 'app:process-handover-protocols',
    description: 'Create handover protocols, send reminders, and force-release expired handovers',
)]
final class ProcessHandoverProtocolsCommand extends Command
{
    private const int DAYS_BEFORE_END = 7;
    private const int FORCE_RELEASE_DAYS = 14;

    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly HandoverProtocolRepository $handoverProtocolRepository,
        #[Autowire(service: 'command.bus')]
        private readonly MessageBusInterface $commandBus,
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();

        $created = $this->createHandoverProtocols($io, $now);
        $reminded = $this->sendReminders($io, $now);
        $released = $this->forceReleaseExpired($io, $now);

        $io->success(sprintf(
            'Created: %d, Reminders: %d, Force-released: %d',
            $created,
            $reminded,
            $released,
        ));

        return Command::SUCCESS;
    }

    private function createHandoverProtocols(SymfonyStyle $io, \DateTimeImmutable $now): int
    {
        $threshold = $now->modify('+'.self::DAYS_BEFORE_END.' days');
        $created = 0;

        // Find contracts ending within 7 days (LIMITED)
        $expiringContracts = $this->contractRepository->findExpiringWithinDays(self::DAYS_BEFORE_END, $now);

        // Find contracts with termination notice within 7 days (UNLIMITED)
        $terminatingContracts = $this->contractRepository->findDueForTermination($threshold);

        $contracts = array_merge($expiringContracts, $terminatingContracts);
        $seen = [];

        foreach ($contracts as $contract) {
            $id = $contract->id->toRfc4122();
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            if ($contract->isTerminated()) {
                continue;
            }

            $existing = $this->handoverProtocolRepository->findByContract($contract);
            if (null !== $existing) {
                continue;
            }

            try {
                $this->commandBus->dispatch(new CreateHandoverProtocolCommand($contract->id));
                $this->entityManager->flush();
                ++$created;
                $io->text(sprintf('  [OK] Created handover for contract %s', substr($id, 0, 8)));
            } catch (\Exception $e) {
                $this->logger->error('Failed to create handover protocol', [
                    'contract_id' => $id,
                    'exception' => $e,
                ]);
                $io->error(sprintf('  [ERROR] Contract %s: %s', substr($id, 0, 8), $e->getMessage()));
            }
        }

        // Fallback: create for already-terminated contracts that somehow don't have a handover
        $terminatedContracts = $this->contractRepository->findDueForTermination($now);
        foreach ($terminatedContracts as $contract) {
            $id = $contract->id->toRfc4122();
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $existing = $this->handoverProtocolRepository->findByContract($contract);
            if (null !== $existing) {
                continue;
            }

            try {
                $this->commandBus->dispatch(new CreateHandoverProtocolCommand($contract->id));
                $this->entityManager->flush();
                ++$created;
                $io->text(sprintf('  [OK] Created handover (fallback) for contract %s', substr($id, 0, 8)));
            } catch (\Exception $e) {
                $this->logger->error('Failed to create handover protocol (fallback)', [
                    'contract_id' => $id,
                    'exception' => $e,
                ]);
            }
        }

        return $created;
    }

    private function sendReminders(SymfonyStyle $io, \DateTimeImmutable $now): int
    {
        $protocols = $this->handoverProtocolRepository->findIncompleteForReminders($now);
        $reminded = 0;

        foreach ($protocols as $protocol) {
            try {
                $protocol->recordReminderSent($now);

                $this->eventBus->dispatch(new HandoverReminderDue(
                    handoverProtocolId: $protocol->id,
                    contractId: $protocol->contract->id,
                    reminderNumber: $protocol->remindersSentCount,
                    occurredOn: $now,
                ));

                $this->entityManager->flush();
                ++$reminded;

                $io->text(sprintf(
                    '  [OK] Reminder #%d sent for handover %s',
                    $protocol->remindersSentCount,
                    substr($protocol->id->toRfc4122(), 0, 8),
                ));
            } catch (\Exception $e) {
                $this->logger->error('Failed to send handover reminder', [
                    'handover_protocol_id' => $protocol->id->toRfc4122(),
                    'exception' => $e,
                ]);
            }
        }

        return $reminded;
    }

    private function forceReleaseExpired(SymfonyStyle $io, \DateTimeImmutable $now): int
    {
        $protocols = $this->handoverProtocolRepository->findExpiredForForceRelease($now, self::FORCE_RELEASE_DAYS);
        $released = 0;

        foreach ($protocols as $protocol) {
            try {
                $this->eventBus->dispatch(new HandoverExpired(
                    handoverProtocolId: $protocol->id,
                    contractId: $protocol->contract->id,
                    occurredOn: $now,
                ));

                $this->entityManager->flush();
                ++$released;

                $io->text(sprintf(
                    '  [OK] Force-released storage for handover %s',
                    substr($protocol->id->toRfc4122(), 0, 8),
                ));
            } catch (\Exception $e) {
                $this->logger->error('Failed to force-release storage', [
                    'handover_protocol_id' => $protocol->id->toRfc4122(),
                    'exception' => $e,
                ]);
            }
        }

        return $released;
    }
}
