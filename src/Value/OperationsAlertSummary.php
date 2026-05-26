<?php

declare(strict_types=1);

namespace App\Value;

use App\Entity\Contract;
use App\Entity\HandoverProtocol;
use App\Entity\Order;

/**
 * Snapshot of every operator-pending signal aggregated by
 * App\Service\Operations\OperationsAlertsBuilder for the admin Operations hub.
 *
 * totalPending sums everything except overdue (overdue keeps its own sidebar
 * entry — including it here would double-count the badge).
 */
final readonly class OperationsAlertSummary
{
    /**
     * @param HandoverProtocol[] $handoverViews                  non-completed protocols, sorted createdAt ASC
     * @param Contract[]         $contractsEndingWithoutProtocol end-date in ≤7 d, no protocol row
     * @param Order[]            $onboardingSignedUnpaid         admin-created, signed, unpaid
     * @param Contract[]         $externalPrepaymentEnding       paid-through-date in ≤7 d
     */
    public function __construct(
        public int $handoverWaitingTenantCount,
        public int $handoverWaitingLandlordCount,
        public int $handoverOverdueCount,
        public array $handoverViews,
        public int $contractsEndingWithoutProtocolCount,
        public array $contractsEndingWithoutProtocol,
        public int $onboardingSignedUnpaidCount,
        public array $onboardingSignedUnpaid,
        public int $externalPrepaymentEndingCount,
        public array $externalPrepaymentEnding,
        public int $overdueCount,
        public int $overdueAmount,
        public int $totalPending,
        public int $unpaidFinesCount = 0,
    ) {
    }
}
