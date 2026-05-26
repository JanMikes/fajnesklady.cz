<?php

declare(strict_types=1);

namespace App\Console;

use App\Event\FinePaymentReminderRequested;
use App\Repository\FineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:send-fine-payment-reminders',
    description: 'Send D+7 and D+14 payment reminders for unpaid fines',
)]
final class SendFinePaymentRemindersCommand extends Command
{
    public function __construct(
        private readonly FineRepository $fineRepository,
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
        $sent = 0;

        // Stage 1: D+7 reminders
        $stage1Fines = $this->fineRepository->findUnpaidForReminder(7, true);
        foreach ($stage1Fines as $fine) {
            try {
                $this->eventBus->dispatch(new FinePaymentReminderRequested(
                    fineId: $fine->id,
                    stage: 1,
                    occurredOn: $now,
                ));

                $fine->markReminder1Sent($now);
                // Console command — no middleware, flush explicitly
                $this->entityManager->flush();
                ++$sent;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send fine reminder (stage 1)', [
                    'fine_id' => $fine->id->toRfc4122(),
                    'exception' => $e,
                ]);
            }
        }

        // Stage 2: D+14 reminders
        $stage2Fines = $this->fineRepository->findUnpaidForReminder(14, false);
        foreach ($stage2Fines as $fine) {
            try {
                $this->eventBus->dispatch(new FinePaymentReminderRequested(
                    fineId: $fine->id,
                    stage: 2,
                    occurredOn: $now,
                ));

                $fine->markReminder2Sent($now);
                // Console command — no middleware, flush explicitly
                $this->entityManager->flush();
                ++$sent;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send fine reminder (stage 2)', [
                    'fine_id' => $fine->id->toRfc4122(),
                    'exception' => $e,
                ]);
            }
        }

        $io->success(sprintf('Sent %d fine payment reminder(s).', $sent));

        return Command::SUCCESS;
    }
}
