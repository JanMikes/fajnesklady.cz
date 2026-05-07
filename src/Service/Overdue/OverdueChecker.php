<?php

declare(strict_types=1);

namespace App\Service\Overdue;

use App\Entity\Contract;
use App\Entity\Place;
use App\Repository\ContractRepository;
use App\Value\OverdueContractView;
use App\Value\OverdueSeverity;
use App\Value\OverdueSummary;
use Symfony\Component\Uid\Uuid;

final readonly class OverdueChecker
{
    public function __construct(
        private ContractRepository $contractRepository,
    ) {
    }

    /**
     * @return OverdueContractView[] sorted: severity DESC, daysOverdue DESC
     */
    public function findOverdueViews(\DateTimeImmutable $now): array
    {
        $contracts = $this->contractRepository->findWithPaymentIssues($now);
        $views = array_map(fn (Contract $c): OverdueContractView => $this->buildView($c, $now), $contracts);

        usort($views, function (OverdueContractView $a, OverdueContractView $b): int {
            $bySeverity = $b->severity->sortRank() <=> $a->severity->sortRank();

            return 0 !== $bySeverity ? $bySeverity : ($b->daysOverdue <=> $a->daysOverdue);
        });

        return $views;
    }

    public function summarise(\DateTimeImmutable $now): OverdueSummary
    {
        $views = $this->findOverdueViews($now);
        $totalAmount = array_sum(array_map(static fn (OverdueContractView $v): int => $v->overdueAmount, $views));

        return new OverdueSummary(
            count: count($views),
            totalAmount: $totalAmount,
            top: array_slice($views, 0, 5),
        );
    }

    public function summariseForPlace(\DateTimeImmutable $now, Place $place): OverdueSummary
    {
        $views = array_values(array_filter(
            $this->findOverdueViews($now),
            static fn (OverdueContractView $v): bool => $v->contract->storage->place->id->equals($place->id),
        ));

        $totalAmount = array_sum(array_map(static fn (OverdueContractView $v): int => $v->overdueAmount, $views));

        return new OverdueSummary(
            count: count($views),
            totalAmount: $totalAmount,
            top: array_slice($views, 0, 5),
        );
    }

    /**
     * Subset of the given user IDs that currently have ≥1 overdue contract.
     *
     * @param Uuid[] $userIds
     *
     * @return string[] RFC-4122 strings — for cheap template membership tests
     *                  via array_flip + isset(..)
     */
    public function filterOverdueUserIds(\DateTimeImmutable $now, array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        return $this->contractRepository->findOverdueUserIds($now, $userIds);
    }

    private function buildView(Contract $contract, \DateTimeImmutable $now): OverdueContractView
    {
        if (null !== $contract->terminatedAt && null !== $contract->outstandingDebtAmount && $contract->outstandingDebtAmount > 0) {
            $anchor = $contract->terminatedAt;

            return new OverdueContractView(
                contract: $contract,
                daysOverdue: max(1, (int) $anchor->diff($now)->days),
                overdueAmount: $contract->outstandingDebtAmount,
                severity: OverdueSeverity::CRITICAL,
                reasonLabel: 'Dluh — smlouva ukončena',
                anchorDate: $anchor,
            );
        }

        $anchor = $contract->nextBillingDate ?? $now;
        $monthlyRate = $contract->order->firstPaymentPrice;
        $attempts = $contract->failedBillingAttempts;

        if ($attempts >= 1) {
            return new OverdueContractView(
                contract: $contract,
                daysOverdue: max(1, (int) $anchor->diff($now)->days),
                // GoPay retries don't accrue a new period — same charge is being retried.
                overdueAmount: $monthlyRate,
                severity: OverdueSeverity::ERROR,
                reasonLabel: sprintf('Selhání platby (%d×)', $attempts),
                anchorDate: $anchor,
            );
        }

        return new OverdueContractView(
            contract: $contract,
            daysOverdue: max(1, (int) $anchor->diff($now)->days),
            overdueAmount: $monthlyRate,
            severity: OverdueSeverity::WARNING,
            reasonLabel: 'Strhnutí splatné',
            anchorDate: $anchor,
        );
    }
}
