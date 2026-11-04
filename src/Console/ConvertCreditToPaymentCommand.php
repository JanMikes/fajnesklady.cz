<?php

declare(strict_types=1);

namespace App\Console;

use App\Command\ConvertCreditToPaymentCommand as ConvertCreditToPayment;
use App\Repository\BankTransactionRepository;
use App\Repository\ContractRepository;
use App\Service\Messenger\HandlerFailureUnwrap;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:convert-credit-to-payment',
    description: 'Record a contract\'s credit (bank-transfer surplus) as an invoiced payment for the cycle that transfer paid.',
)]
final class ConvertCreditToPaymentCommand extends Command
{
    public function __construct(
        private readonly ContractRepository $contractRepository,
        private readonly BankTransactionRepository $bankTransactionRepository,
        private readonly MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('contractId', InputArgument::REQUIRED)
            ->addArgument('bankTransactionId', InputArgument::REQUIRED, 'The transfer the surplus came from (payment evidence).')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would happen without writing anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $contract = $this->contractRepository->get(Uuid::fromString((string) $input->getArgument('contractId')));
        $bankTransaction = $this->bankTransactionRepository->find(Uuid::fromString((string) $input->getArgument('bankTransactionId')));

        if (null === $bankTransaction) {
            $io->error('Bank transaction not found.');

            return Command::FAILURE;
        }

        $io->text(sprintf(
            'Contract %s — credit %s Kč, transfer %s (%s Kč, %s)',
            $contract->id,
            number_format($contract->creditBalance / 100, 2, ',', ' '),
            $bankTransaction->id,
            number_format($bankTransaction->amount / 100, 2, ',', ' '),
            $bankTransaction->transactionDate->format('d.m.Y'),
        ));

        if ($input->getOption('dry-run')) {
            $io->note('Dry run — nothing written.');

            return Command::SUCCESS;
        }

        try {
            // Through the command bus: its transaction flushes the payment and
            // the invoice, and buffered events (invoice e-mail) are dispatched.
            $this->commandBus->dispatch(new ConvertCreditToPayment($contract, $bankTransaction));
        } catch (\Throwable $e) {
            $io->error(HandlerFailureUnwrap::unwrap($e)->getMessage());

            return Command::FAILURE;
        }

        $io->success('Credit recorded as a payment and invoiced.');

        return Command::SUCCESS;
    }
}
