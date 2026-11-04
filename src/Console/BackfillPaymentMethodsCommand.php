<?php

declare(strict_types=1);

namespace App\Console;

use App\Enum\PaymentMethod;
use App\Repository\InvoiceRepository;
use App\Service\Fakturoid\FakturoidClient;
use App\Service\Payment\PaymentMethodBackfill;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off: record how every historical payment arrived (card / bank transfer /
 * off-system) and print it on the already issued Fakturoid invoices.
 *
 * Invoices that turn out to document off-system money are reported, not
 * relabelled: such money is never invoiced, and cancelling an already issued
 * tax document is for the accountant to decide.
 *
 * Until now every invoice carried Fakturoid's account default as "Způsob
 * platby" — the card/transfer distinction the accountant needs was nowhere on
 * the document (the 'card' we sent along with each payment record is not an
 * attribute Fakturoid knows, so it was silently dropped).
 *
 * Idempotent: the database steps only touch rows whose method is still
 * unknown, and re-sending an invoice's payment method is harmless.
 */
#[AsCommand(
    name: 'app:backfill-payment-methods',
    description: 'Record card / bank-transfer / off-system on historical payments and their Fakturoid invoices.',
)]
final class BackfillPaymentMethodsCommand extends Command
{
    public function __construct(
        private readonly PaymentMethodBackfill $backfill,
        private readonly InvoiceRepository $invoiceRepository,
        private readonly FakturoidClient $fakturoidClient,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing to the database or Fakturoid.')
            ->addOption('skip-fakturoid', null, InputOption::VALUE_NONE, 'Only backfill the database; leave Fakturoid invoices untouched.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        // Order matters: steps 2 and 4 read the links step 1 completes, and
        // the invoice resolution reads all of them (unflushed changes are
        // visible through the identity map).
        $io->section('1/4 First-payment source links');
        $io->listing($this->backfill->linkFirstPayments() ?: ['nothing to do']);

        $io->section('2/4 Orders — first payment method');
        $io->listing($this->backfill->backfillOrderPaidVia() ?: ['nothing to do']);

        $io->section('3/4 Orders — onboarding debt method');
        $io->listing($this->backfill->backfillDebtPaidVia() ?: ['nothing to do']);

        $io->section('4/4 Fines');
        $io->listing($this->backfill->backfillFinePaidVia() ?: ['nothing to do']);

        if (!$dryRun) {
            // Manual flush: console commands run outside the command bus, so no
            // doctrine_transaction middleware wraps this. Only plain column
            // writes happen above — no domain events are recorded, so none are
            // left buffered by flushing outside the bus.
            $this->entityManager->flush();
        }

        $io->section('Fakturoid invoices');
        $rows = [];
        $failed = 0;
        foreach ($this->invoiceRepository->findAllOrderedByIssuedAt() as $invoice) {
            $paidVia = $this->backfill->resolveInvoicePaymentMethod($invoice);

            if (null === $paidVia) {
                $rows[] = [$invoice->invoiceNumber, $invoice->issuedAt->format('d.m.Y'), number_format($invoice->amount / 100, 0, ',', ' '), '?', 'SKIPPED — no matching payment'];
                ++$failed;

                continue;
            }

            // Off-system money is never invoiced (no evidence of the payment).
            // Invoices issued for it before that rule are left untouched —
            // whether to cancel a tax document is the accountant's call.
            if (PaymentMethod::EXTERNAL === $paidVia) {
                $rows[] = [$invoice->invoiceNumber, $invoice->issuedAt->format('d.m.Y'), number_format($invoice->amount / 100, 0, ',', ' '), $paidVia->label(), 'NOT CHANGED — off-system payment should not have an invoice; review with the accountant'];
                ++$failed;

                continue;
            }

            $result = $dryRun ? 'dry-run' : 'skipped (--skip-fakturoid)';
            if (!$dryRun && !$input->getOption('skip-fakturoid')) {
                try {
                    $this->fakturoidClient->updateInvoicePaymentMethod($invoice->fakturoidInvoiceId, $paidVia);
                    $result = 'updated';
                } catch (\Throwable $e) {
                    $result = 'FAILED — '.$e->getMessage();
                    ++$failed;
                }
            }

            $rows[] = [$invoice->invoiceNumber, $invoice->issuedAt->format('d.m.Y'), number_format($invoice->amount / 100, 0, ',', ' '), $paidVia->label(), $result];
        }

        $io->table(['Faktura', 'Vystavena', 'Kč', 'Způsob platby', 'Výsledek'], $rows);

        if ($failed > 0) {
            $io->warning(sprintf('%d invoice(s) need manual attention in Fakturoid.', $failed));

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry run finished — nothing written.' : 'Payment methods backfilled.');

        return Command::SUCCESS;
    }
}
