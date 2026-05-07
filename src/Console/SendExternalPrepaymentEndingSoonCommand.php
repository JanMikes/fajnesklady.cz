<?php

declare(strict_types=1);

namespace App\Console;

use App\Event\ExternalPrepaymentEndingSoon;
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
 * Daily cron: notify customers whose external (non-GoPay) prepayment ends
 * within DAYS_AHEAD calendar days, asking them to set up automatic payment.
 *
 * Mirrors {@see SendRecurringPaymentAdvanceNoticeCommand} for the GoPay
 * 6-month-gap notice; reuses Contract.lastAdvanceNoticeSentAt for daily
 * idempotency so the same contract is not notified twice.
 */
#[AsCommand(
    name: 'app:send-external-prepayment-ending-soon',
    description: 'Notify customers 7 days before their external (non-GoPay) prepayment runs out.',
)]
final class SendExternalPrepaymentEndingSoonCommand extends Command
{
    private const int DAYS_AHEAD = 7;

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

        $rangeStart = $now->setTime(0, 0, 0);
        $rangeEnd = $rangeStart->modify('+'.self::DAYS_AHEAD.' days');

        $contracts = $this->contractRepository->findExternalPrepaymentsEndingInRange($rangeStart, $rangeEnd);

        if (0 === count($contracts)) {
            $io->info('No external prepayments end within the notice window.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d contracts with external prepayment ending within %d days.', count($contracts), self::DAYS_AHEAD));

        foreach ($contracts as $contract) {
            $this->eventBus->dispatch(new ExternalPrepaymentEndingSoon(
                contractId: $contract->id,
                occurredOn: $now,
            ));

            $io->text(sprintf(
                '  → Notice dispatched for contract %s (paid through %s).',
                $contract->id->toRfc4122(),
                $contract->paidThroughDate?->format('d.m.Y') ?? '?',
            ));
        }

        $io->success('External prepayment ending notices dispatched.');

        return Command::SUCCESS;
    }
}
