<?php

declare(strict_types=1);

namespace App\Console;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\InvoicingService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Backstop for the first-payment invoice. The happy path issues the invoice
 * synchronously inside SendRentalActivatedEmailHandler so it can be bundled
 * into the post-payment e-mail. This command catches the cases where that
 * synchronous issuance failed (typically Fakturoid was unreachable). For
 * each completed order without an invoice — and paid more than 15 minutes
 * ago, so we don't race the synchronous path — it issues the invoice via
 * InvoicingService; the resulting InvoiceCreated event triggers the
 * standalone SendInvoiceEmailHandler, which sends the customer a separate
 * "Faktura" e-mail because the invoice is no longer in time to be bundled.
 */
#[AsCommand(
    name: 'app:issue-missing-invoices',
    description: 'Issue invoices for paid orders that ended up without one (Fakturoid retry backstop). Designed for cron.',
)]
final class IssueMissingInvoicesCommand extends Command
{
    private const GRACE_MINUTES = 15;

    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly InvoicingService $invoicingService,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();
        $cutoff = $now->modify(sprintf('-%d minutes', self::GRACE_MINUTES));

        $orders = $this->orderRepository->findCompletedWithoutInvoice($cutoff);

        if (0 === count($orders)) {
            $io->info('No completed orders missing an invoice.');

            return Command::SUCCESS;
        }

        $io->info(sprintf('Found %d completed order(s) missing an invoice.', count($orders)));

        $successCount = 0;
        $failureCount = 0;

        foreach ($orders as $order) {
            try {
                $this->invoicingService->issueInvoiceForOrder($order, $now);
                $this->getEntityManager()->flush();
                ++$successCount;
                $io->text(sprintf('  [OK] Order %s — invoice issued.', $order->id));
            } catch (\Throwable $e) {
                ++$failureCount;
                $this->recordFailure($order, $e);
                $io->error(sprintf('  [FAIL] Order %s: %s', $order->id, $e->getMessage()));
            }
        }

        $io->success(sprintf('Processed: %d success, %d failures.', $successCount, $failureCount));

        return $failureCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Wrapped so a follow-up error (closed EntityManager after a Doctrine
     * rollback, …) can't break the rest of the cron run — the unrecorded
     * failure surfaces again on the next tick.
     */
    private function recordFailure(Order $order, \Throwable $exception): void
    {
        try {
            $this->logger->error('Failed to issue missing invoice for completed order', [
                'order_id' => $order->id->toRfc4122(),
                'exception' => $exception,
            ]);
        } catch (\Throwable $followUp) {
            $this->logger->critical('Failed to log missing-invoice failure — resetting EntityManager and continuing', [
                'order_id' => $order->id->toRfc4122(),
                'original_exception' => $exception,
                'follow_up_exception' => $followUp,
            ]);

            $this->doctrine->resetManager();
        }
    }

    private function getEntityManager(): EntityManagerInterface
    {
        $manager = $this->doctrine->getManager();
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Default Doctrine manager is not an ORM EntityManager.');
        }

        if (!$manager->isOpen()) {
            $this->doctrine->resetManager();
            $reset = $this->doctrine->getManager();
            \assert($reset instanceof EntityManagerInterface);

            return $reset;
        }

        return $manager;
    }
}
