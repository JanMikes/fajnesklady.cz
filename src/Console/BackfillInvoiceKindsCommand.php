<?php

declare(strict_types=1);

namespace App\Console;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Enum\InvoiceKind;
use App\Enum\OrderStatus;
use App\Enum\PaymentMethod;
use App\Repository\InvoiceRepository;
use App\Repository\OrderRepository;
use App\Service\Payment\PaymentMethodBackfill;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One-off: classify invoices issued before Invoice::$kind existed, using the
 * same invoice↔payment matching as the GoPay-id backfill (spec 093). New
 * invoices get their kind from InvoicingService.
 *
 * Why it matters: the first-payment checks (rental-activated e-mail, the
 * app:issue-missing-invoices backstop) only count FIRST_PAYMENT invoices
 * plus unclassified history. Classifying an onboarding-debt invoice therefore
 * lets the backstop issue a first-payment invoice that was wrongly skipped
 * (order 019f5d05, 2026-11). The command lists exactly which orders the
 * backstop will then invoice — on a dry run before anything is written.
 *
 * Rows that cannot be matched stay unclassified (and keep counting as a
 * possible first-payment invoice, so they can never cause a duplicate).
 * Idempotent: only rows without a kind are visited.
 */
#[AsCommand(
    name: 'app:backfill-invoice-kinds',
    description: 'Classify historical invoices (first payment / onboarding debt / recurring / fine / credit note).',
)]
final class BackfillInvoiceKindsCommand extends Command
{
    public function __construct(
        private readonly InvoiceRepository $invoiceRepository,
        private readonly OrderRepository $orderRepository,
        private readonly PaymentMethodBackfill $resolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Resolve and report, but write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var array<string, InvoiceKind> $planned invoice id → kind */
        $planned = [];
        /** @var array<string, Invoice> $byId */
        $byId = [];
        /** @var array<string, int> $counts */
        $counts = [];
        $unresolved = [];

        foreach ($this->invoiceRepository->findWithoutKind() as $invoice) {
            $kind = $this->resolver->resolveInvoiceKind($invoice);

            if (null === $kind) {
                $unresolved[] = [$invoice->invoiceNumber, $invoice->issuedAt->format('Y-m-d H:i'), number_format($invoice->amount / 100, 2, ',', ' '), $invoice->order->id->toRfc4122()];

                continue;
            }

            $planned[$invoice->id->toRfc4122()] = $kind;
            $byId[$invoice->id->toRfc4122()] = $invoice;
            $counts[$kind->value] = ($counts[$kind->value] ?? 0) + 1;
        }

        ksort($counts);
        $io->section($dryRun ? 'Would classify' : 'Classified');
        $io->table(['kind', 'invoices'], array_map(static fn (string $k, int $n): array => [$k, $n], array_keys($counts), array_values($counts)));

        if ([] !== $unresolved) {
            $io->warning(sprintf('%d invoice(s) could not be matched and stay unclassified (still counted as a possible first-payment invoice):', count($unresolved)));
            $io->table(['invoice', 'issued', 'amount Kč', 'order'], $unresolved);
        }

        if ($dryRun) {
            $orders = $this->ordersLackingFirstPaymentInvoiceAfter($planned, $byId);
            $this->reportBackstopTargets($io, $orders, 'After this backfill the app:issue-missing-invoices backstop WOULD issue a first-payment invoice for');

            return Command::SUCCESS;
        }

        foreach ($byId as $id => $invoice) {
            $invoice->backfillKind($planned[$id]);
        }

        // Console command: no doctrine_transaction middleware. Only the kind
        // column of existing invoices changes — no domain events are recorded.
        $this->entityManager->flush();

        $this->reportBackstopTargets(
            $io,
            $this->orderRepository->findCompletedWithoutInvoice($this->clock->now()),
            'The app:issue-missing-invoices backstop will now issue a first-payment invoice for',
        );

        return Command::SUCCESS;
    }

    /**
     * Dry-run simulation of OrderRepository::findCompletedWithoutInvoice()
     * with the planned kinds applied, for the orders whose invoices change.
     *
     * @param array<string, InvoiceKind> $planned
     * @param array<string, Invoice>     $byId
     *
     * @return list<Order>
     */
    private function ordersLackingFirstPaymentInvoiceAfter(array $planned, array $byId): array
    {
        $orders = [];
        foreach ($byId as $invoice) {
            $orders[$invoice->order->id->toRfc4122()] = $invoice->order;
        }

        $lacking = [];
        foreach ($orders as $order) {
            if (OrderStatus::COMPLETED !== $order->status || $order->firstPaymentPrice <= 0 || null === $order->paidAt
                || PaymentMethod::EXTERNAL === $order->paymentMethod || PaymentMethod::EXTERNAL === $order->paidVia) {
                continue;
            }

            $covered = false;
            foreach ($this->invoiceRepository->findAllByOrder($order) as $invoice) {
                $kind = $invoice->kind ?? $planned[$invoice->id->toRfc4122()] ?? null;
                if (InvoiceKind::FIRST_PAYMENT === $kind || (null === $kind && null === $invoice->fine && !$invoice->isCreditNote())) {
                    $covered = true;

                    break;
                }
            }

            if (!$covered) {
                $lacking[] = $order;
            }
        }

        return $lacking;
    }

    /**
     * @param array<Order> $orders
     */
    private function reportBackstopTargets(SymfonyStyle $io, array $orders, string $headline): void
    {
        if ([] === $orders) {
            $io->success('No order is left without a first-payment invoice.');

            return;
        }

        $io->note(sprintf('%s %d order(s):', $headline, count($orders)));
        $io->table(
            ['order', 'customer', 'paid at', 'first payment Kč', 'paid via', 'GoPay id'],
            array_map(static fn (Order $o): array => [
                $o->id->toRfc4122(),
                $o->user->email,
                $o->paidAt?->format('Y-m-d H:i'),
                number_format($o->firstPaymentPrice / 100, 2, ',', ' '),
                $o->paidVia?->value,
                $o->goPayPaymentId,
            ], $orders),
        );
    }
}
