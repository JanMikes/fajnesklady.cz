<?php

declare(strict_types=1);

namespace App\Console;

use App\Entity\Order;
use App\Repository\OrderRepository;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

/**
 * Console command to expire orders that have passed their expiration date.
 *
 * Should be run as a scheduled task (e.g., cron job every hour).
 */
#[AsCommand(
    name: 'app:expire-orders',
    description: 'Expire orders that have passed their expiration date',
)]
final class ExpireOrdersCommand extends Command
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderRepository $orderRepository,
        private readonly ClockInterface $clock,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = $this->clock->now();

        // Process each order in its own flush so a single failure (e.g. a
        // constraint hiccup while releasing one storage) can't abort the whole
        // run and leave the remaining expired orders stuck RESERVED — and thus
        // blocking re-booking of those units. Mirrors the per-entity cron
        // pattern in .claude/MESSENGER.md §3.
        $ids = array_map(
            static fn (Order $order): Uuid => $order->id,
            $this->orderRepository->findExpiredOrders($now),
        );

        $expired = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $order = $this->orderRepository->find($id);
                if (null === $order || !$order->isExpired($now)) {
                    continue;
                }

                $this->orderService->expireOrder($order, $now);
                $this->entityManager->flush();
                ++$expired;
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('Failed to expire order', [
                    'order_id' => $id->toRfc4122(),
                    'exception' => $e,
                ]);
                // Reset the manager so a half-applied unit of work doesn't
                // poison the next iteration; the next order is re-fetched fresh.
                $this->entityManager->clear();
            }
        }

        if ($failed > 0) {
            $io->warning(sprintf('%d order(s) failed to expire (see logs).', $failed));
        }

        if ($expired > 0) {
            $io->success(sprintf('Expired %d order(s).', $expired));
        } else {
            $io->info('No orders to expire.');
        }

        return Command::SUCCESS;
    }
}
