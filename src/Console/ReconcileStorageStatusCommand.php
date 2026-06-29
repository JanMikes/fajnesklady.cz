<?php

declare(strict_types=1);

namespace App\Console;

use App\Entity\Storage;
use App\Enum\StorageStatus;
use App\Repository\StorageRepository;
use App\Service\Storage\StorageStatusReconciler;
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
 * Heals drift between the denormalized `storage.status` column and the live
 * Order / Contract / StorageUnavailability rows it is meant to cache.
 *
 * The column is mutated by discrete lifecycle events (reserve / occupy /
 * release / markUnavailable). Any missed transition — or a date-bounded manual
 * block that lapses or is deleted after its window — leaves the column stale,
 * which is invisible on the live surfaces (map / list / calendar) but wrong on
 * the canvas and the dashboard counters that read the column directly.
 *
 * This command recomputes each storage's status from its live bookings and
 * writes back only the ones that differ, making the cache self-healing. It also
 * activates / deactivates date-bounded manual blocks as their start/end dates
 * cross "today" (e.g. a block whose start_date is tomorrow flips the unit to
 * manually_unavailable once that date arrives).
 *
 * Should be run as a scheduled task (hourly cron).
 */
#[AsCommand(
    name: 'app:reconcile-storage-status',
    description: 'Recompute the denormalized storage.status from live bookings, healing any drift',
)]
final class ReconcileStorageStatusCommand extends Command
{
    public function __construct(
        private readonly StorageRepository $storageRepository,
        private readonly StorageStatusReconciler $reconciler,
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

        $storages = $this->storageRepository->findAll();

        // Derive every status up front in a handful of bulk queries (reusing the
        // exact live computation the map / list render from), then apply each
        // change in its own flush so a single failure can't abort the whole run
        // and leave the remaining drifted units uncorrected. Mirrors the
        // per-entity cron pattern in .claude/MESSENGER.md §3.
        $derivedById = $this->reconciler->deriveStatuses($storages, $now);
        $ids = array_map(static fn (Storage $storage): Uuid => $storage->id, $storages);

        $reconciled = 0;
        $failed = 0;

        foreach ($ids as $id) {
            $key = $id->toRfc4122();
            $derived = $derivedById[$key] ?? null;
            if (!$derived instanceof StorageStatus) {
                continue;
            }

            try {
                $storage = $this->storageRepository->find($id);
                if (null === $storage) {
                    continue;
                }

                $previous = $storage->status;
                if (!$storage->reconcileStatusTo($derived, $now)) {
                    continue;
                }

                $this->entityManager->flush();
                ++$reconciled;

                $this->logger->info('Reconciled drifted storage status', [
                    'storage_id' => $key,
                    'from' => $previous->value,
                    'to' => $derived->value,
                ]);
            } catch (\Throwable $e) {
                ++$failed;
                $this->logger->error('Failed to reconcile storage status', [
                    'storage_id' => $key,
                    'exception' => $e,
                ]);
                // Reset the manager so a half-applied unit of work doesn't poison
                // the next iteration; the next storage is re-fetched fresh.
                $this->entityManager->clear();
            }
        }

        if ($failed > 0) {
            $io->warning(sprintf('%d storage(s) failed to reconcile (see logs).', $failed));
        }

        if ($reconciled > 0) {
            $io->success(sprintf('Reconciled %d storage status(es).', $reconciled));
        } else {
            $io->info('All storage statuses already in sync.');
        }

        return Command::SUCCESS;
    }
}
