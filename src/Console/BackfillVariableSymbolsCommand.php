<?php

declare(strict_types=1);

namespace App\Console;

use App\Entity\Order;
use App\Service\Payment\VariableSymbolGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off backfill for spec 089: before it, a variable symbol was only assigned
 * to BANK_TRANSFER orders, so card / external orders had none and could not be
 * paid (or have their debt paid) by wire. Every order now gets one at creation;
 * this command catches the legacy rows.
 *
 * Idempotent: the generator is deterministic on the order id, and orders that
 * already have a symbol are not selected at all.
 */
#[AsCommand(
    name: 'app:backfill-variable-symbols',
    description: 'Assign a variable symbol to every order that lacks one (spec 089).',
)]
final class BackfillVariableSymbolsCommand extends Command
{
    private const int BATCH_SIZE = 50;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly VariableSymbolGenerator $variableSymbolGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report how many orders would be updated without writing anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var Order[] $orders */
        $orders = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(Order::class, 'o')
            ->where('o.variableSymbol IS NULL')
            ->orderBy('o.createdAt', 'ASC')
            ->getQuery()
            ->getResult();

        if ([] === $orders) {
            $io->info('No orders without a variable symbol.');

            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->info(sprintf('%d order(s) would be assigned a variable symbol.', \count($orders)));

            return Command::SUCCESS;
        }

        $assigned = 0;

        foreach ($orders as $order) {
            $order->assignVariableSymbol($this->variableSymbolGenerator->generate($order->id));
            ++$assigned;

            if (0 === $assigned % self::BATCH_SIZE) {
                // Manual flush: console commands run outside the command bus, so no
                // doctrine_transaction middleware wraps this. Flushing in batches is
                // also required for correctness — VariableSymbolGenerator checks
                // uniqueness with a DQL query, which cannot see unflushed assignments,
                // so an unbounded batch would widen the window for a crc32 collision
                // to slip past the check. The column's unique index is the backstop.
                $this->entityManager->flush();
            }
        }

        // Flush the trailing partial batch — without this the last <50 assignments
        // are silently discarded when the command exits.
        $this->entityManager->flush();

        $io->success(sprintf('Assigned %d variable symbol(s).', $assigned));

        return Command::SUCCESS;
    }
}
