<?php

declare(strict_types=1);

namespace App\Console;

use App\Event\ContractExpiringSoon;
use App\Service\ContractService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Console command to send expiration reminder emails for contracts.
 *
 * Sends reminders at 7 days and 1 day before contract expiration.
 * Should be run once daily (e.g., cron job at 8:00 AM).
 */
#[AsCommand(
    name: 'app:send-expiration-reminders',
    description: 'Send reminder emails for contracts expiring soon',
)]
final class SendExpirationRemindersCommand extends Command
{
    /**
     * Days before expiration to send reminders.
     */
    private const array REMINDER_DAYS = [7, 1];

    public function __construct(
        private readonly ContractService $contractService,
        #[Autowire(service: 'event.bus')]
        private readonly MessageBusInterface $eventBus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();
        $totalReminders = 0;

        foreach (self::REMINDER_DAYS as $days) {
            $contracts = $this->contractService->findContractsExpiringOnDay($days, $now);

            foreach ($contracts as $contract) {
                $event = new ContractExpiringSoon(
                    contractId: $contract->id,
                    daysRemaining: $days,
                    occurredOn: $now,
                );

                $this->eventBus->dispatch($event);
                ++$totalReminders;

                $io->writeln(sprintf(
                    '  Reminder sent: Contract %s expires in %d day(s)',
                    substr($contract->id->toRfc4122(), 0, 8),
                    $days,
                ));
            }
        }

        if ($totalReminders > 0) {
            $io->success(sprintf('Sent %d expiration reminder(s).', $totalReminders));
        } else {
            $io->info('No contracts expiring soon.');
        }

        return Command::SUCCESS;
    }
}
