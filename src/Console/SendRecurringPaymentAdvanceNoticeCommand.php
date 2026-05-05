<?php

declare(strict_types=1);

namespace App\Console;

use App\Enum\AdvanceNoticeReason;
use App\Event\RecurringPaymentAdvanceNoticeNeeded;
use App\Repository\ContractRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Daily cron: send the 7-business-day advance notice required by Podmínky
 * opakovaných plateb čl. V whenever ≥6 months elapsed since the last
 * successful charge of an active recurring contract.
 *
 * Manual parameter-change notices are dispatched directly from the admin
 * controller — they don't go through this cron.
 */
#[AsCommand(
    name: 'app:send-recurring-payment-advance-notice',
    description: 'Notify recurring-payment customers 7+ working days before a charge that follows a ≥6-month gap (Podmínky čl. V).',
)]
final class SendRecurringPaymentAdvanceNoticeCommand extends Command
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
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

        $contracts = $this->contractRepository->findRequiringAdvanceNotice($now);

        if (0 === count($contracts)) {
            $io->info('No contracts require advance notice today.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d contracts requiring 7-day advance notice.', count($contracts)));

        foreach ($contracts as $contract) {
            $this->eventBus->dispatch(new RecurringPaymentAdvanceNoticeNeeded(
                contractId: $contract->id,
                reason: AdvanceNoticeReason::SIX_MONTH_GAP,
                occurredOn: $now,
            ));

            $io->text(sprintf('  → Notice dispatched for contract %s (next charge %s).', $contract->id, $contract->nextBillingDate?->format('d.m.Y') ?? '?'));
        }

        $io->success('Advance notices dispatched.');

        return Command::SUCCESS;
    }
}
