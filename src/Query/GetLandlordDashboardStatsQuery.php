<?php

declare(strict_types=1);

namespace App\Query;

use App\Repository\ContractRepository;
use App\Repository\PaymentRepository;
use App\Repository\PlaceRepository;
use App\Repository\SelfBillingInvoiceRepository;
use App\Repository\StorageRepository;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GetLandlordDashboardStatsQuery
{
    public function __construct(
        private UserRepository $userRepository,
        private PaymentRepository $paymentRepository,
        private SelfBillingInvoiceRepository $selfBillingInvoiceRepository,
        private ContractRepository $contractRepository,
        private PlaceRepository $placeRepository,
        private StorageRepository $storageRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GetLandlordDashboardStats $query): GetLandlordDashboardStatsResult
    {
        $landlord = $this->userRepository->get($query->landlordId);
        $now = $this->clock->now();

        $lastMonth = $now->modify('first day of last month');
        $lastMonthYear = (int) $lastMonth->format('Y');
        $lastMonthMonth = (int) $lastMonth->format('n');

        $lastMonthRevenue = $this->paymentRepository->sumByStorageOwnerAndPeriod(
            $landlord,
            $lastMonthYear,
            $lastMonthMonth,
        );

        $lastMonthCommission = $this->selfBillingInvoiceRepository->sumCommissionByLandlordAndPeriod(
            $landlord,
            $lastMonthYear,
            $lastMonthMonth,
        );

        $expectedThisMonthRevenue = $this->contractRepository->sumExpectedRecurringByLandlord($landlord);
        $activeRecurringContracts = $this->contractRepository->countActiveRecurringByLandlord($landlord);

        $placesCount = $this->placeRepository->countPlacesWithStoragesByOwner($landlord);
        $totalStorages = $this->storageRepository->countByOwner($landlord);
        $occupiedStorages = $this->storageRepository->countOccupiedByOwner($landlord);
        $availableStorages = $this->storageRepository->countAvailableByOwner($landlord);
        $occupancyRate = $totalStorages > 0 ? ($occupiedStorages / $totalStorages) * 100 : 0.0;

        return new GetLandlordDashboardStatsResult(
            lastMonthRevenue: $lastMonthRevenue,
            lastMonthCommission: $lastMonthCommission,
            expectedThisMonthRevenue: $expectedThisMonthRevenue,
            activeRecurringContracts: $activeRecurringContracts,
            placesCount: $placesCount,
            totalStorages: $totalStorages,
            occupiedStorages: $occupiedStorages,
            availableStorages: $availableStorages,
            occupancyRate: $occupancyRate,
        );
    }
}
