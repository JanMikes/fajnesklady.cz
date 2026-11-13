<?php

declare(strict_types=1);

namespace App\Console;

use App\Entity\Invoice;
use App\Enum\PaymentMethod;
use App\Repository\InvoiceRepository;
use App\Service\Fakturoid\FakturoidClient;
use App\Service\GoPay\GoPayClient;
use App\Service\Payment\PaymentMethodBackfill;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off (spec 093): put the paying GoPay payment id on every historical
 * card-paid Fakturoid invoice as "Číslo objednávky" — the only field
 * Fakturoid exports to ISDOC — so the accountant can pair GoPay payouts with
 * invoices. New invoices carry it from InvoicingService.
 *
 * Every id is confirmed with GoPay (paid / refunded, same amount) before it
 * reaches a tax document: an invoice is linked to its payment only by amount
 * and time, and legacy rows once stored bank-transaction UUIDs as GoPay ids.
 * What cannot be proven is reported, not guessed.
 *
 * Idempotent: only invoices without a stored id are visited, and the local
 * column is written after Fakturoid accepted the value.
 */
#[AsCommand(
    name: 'app:backfill-invoice-gopay-ids',
    description: 'Stamp the paying GoPay payment ID on historical card-paid Fakturoid invoices (Číslo objednávky / ISDOC).',
)]
final class BackfillInvoiceGoPayIdsCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly PaymentMethodBackfill $resolver,
        private readonly GoPayClient $goPayClient,
        private readonly FakturoidClient $fakturoidClient,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Resolve and verify with GoPay, but write nothing to Fakturoid or the database.')
            ->addOption('csv', null, InputOption::VALUE_REQUIRED, 'Write the invoice → GoPay ID list (updated, or would-be-updated on a dry run) to this file for the accountant.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $csv = null;
        $csvPath = $input->getOption('csv');
        if (\is_string($csvPath) && '' !== $csvPath) {
            $csv = @fopen($csvPath, 'w');
            if (false === $csv) {
                $io->error(sprintf('Cannot write the CSV file "%s".', $csvPath));

                return Command::FAILURE;
            }
            fputcsv($csv, ['invoice_number', 'issued_at', 'amount_czk', 'gopay_payment_id', 'result'], ';', '"', '');
        }

        $rows = [];
        $notCard = 0;
        $problems = 0;

        /** @var list<array{Invoice, string}> $candidates */
        $candidates = [];
        foreach ($this->invoiceRepository->findWithoutGoPayPaymentId() as $invoice) {
            $source = $this->resolver->resolveInvoicePaymentSource($invoice);

            if (null === $source) {
                $rows[] = $this->row($invoice, '?', 'SKIPPED — no matching payment, check manually');
                ++$problems;

                continue;
            }

            if (PaymentMethod::GOPAY !== $source->method) {
                ++$notCard;

                continue;
            }

            if (null === $source->goPayPaymentId || '' === $source->goPayPaymentId) {
                $rows[] = $this->row($invoice, '?', 'SKIPPED — card payment without a stored GoPay ID, check manually');
                ++$problems;

                continue;
            }

            $candidates[] = [$invoice, $source->goPayPaymentId];
        }

        // GoPay confirms state and amount, not WHICH invoice a payment paid:
        // two same-amount invoices of one order within a day can resolve to
        // the same id. One payment is invoiced once (only its credit notes
        // share the id), so every invoice in such a collision is reported —
        // none of them is provably the right one.
        $idUses = [];
        foreach ($candidates as [$invoice, $goPayPaymentId]) {
            if (!$invoice->isCreditNote()) {
                $idUses[$goPayPaymentId] = ($idUses[$goPayPaymentId] ?? 0) + 1;
            }
        }

        foreach ($candidates as [$invoice, $goPayPaymentId]) {
            if (!$invoice->isCreditNote()
                && ($idUses[$goPayPaymentId] > 1 || $this->invoiceRepository->existsInvoiceForGoPayPaymentId($goPayPaymentId))) {
                $rows[] = $this->row($invoice, $goPayPaymentId, 'AMBIGUOUS — the ID matches another invoice too, check manually');
                ++$problems;

                continue;
            }

            $refusal = $this->verifyWithGoPay($invoice, $goPayPaymentId);
            if (null !== $refusal) {
                $rows[] = $this->row($invoice, $goPayPaymentId, 'NOT VERIFIED — '.$refusal);
                ++$problems;

                continue;
            }

            $result = 'dry-run';
            if (!$dryRun) {
                try {
                    $this->fakturoidClient->updateInvoiceGoPayPaymentId($invoice->fakturoidInvoiceId, $goPayPaymentId);
                } catch (\Throwable $e) {
                    $rows[] = $this->row($invoice, $goPayPaymentId, 'FAILED — '.$e->getMessage());
                    ++$problems;

                    continue;
                }

                $invoice->backfillGoPayPaymentId($goPayPaymentId);
                // Manual flush: console command outside the command bus, so no
                // doctrine_transaction middleware. A plain column write (no
                // domain events to strand), flushed per invoice so a crash
                // never loses a value Fakturoid already accepted.
                $this->entityManager->flush();
                $result = 'updated';
            }

            $rows[] = $this->row($invoice, $goPayPaymentId, $result);
            if (null !== $csv) {
                fputcsv($csv, [$invoice->invoiceNumber, $invoice->issuedAt->format('Y-m-d'), number_format($invoice->amount / 100, 2, ',', ''), $goPayPaymentId, $result], ';', '"', '');
            }
        }

        if (null !== $csv) {
            fclose($csv);
        }

        $io->table(['Faktura', 'Vystavena', 'Kč', 'GoPay ID', 'Výsledek'], $rows);
        $io->text(sprintf('%d invoice(s) not paid by card — left without a GoPay ID.', $notCard));

        if ($problems > 0) {
            $io->warning(sprintf('%d invoice(s) need manual attention.', $problems));

            return Command::FAILURE;
        }

        $io->success($dryRun ? 'Dry run finished — nothing written.' : 'GoPay payment IDs backfilled.');

        return Command::SUCCESS;
    }

    /**
     * Null when GoPay confirms the id paid this invoice; otherwise why not.
     * A credit note documents a refund of (part of) the payment, so it needs
     * a refunded state, not a matching amount.
     */
    private function verifyWithGoPay(Invoice $invoice, string $goPayPaymentId): ?string
    {
        try {
            $status = $this->goPayClient->getStatus($goPayPaymentId);
        } catch (\Throwable $e) {
            return sprintf('GoPay rejected the ID (%s)', $e->getMessage());
        }

        if ($invoice->isCreditNote()) {
            return $status->isRefunded() ? null : sprintf('GoPay state %s, expected a refund', $status->state);
        }

        if (!$status->isPaid() && !$status->isRefunded()) {
            return sprintf('GoPay state %s', $status->state);
        }

        if ($status->amount !== $invoice->amount) {
            return sprintf('GoPay amount %s ≠ invoice %d haléřů', null === $status->amount ? 'unknown' : (string) $status->amount, $invoice->amount);
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function row(Invoice $invoice, string $goPayPaymentId, string $result): array
    {
        return [
            $invoice->invoiceNumber,
            $invoice->issuedAt->format('d.m.Y'),
            number_format($invoice->amount / 100, 0, ',', ' '),
            $goPayPaymentId,
            $result,
        ];
    }
}
