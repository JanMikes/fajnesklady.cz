<?php

declare(strict_types=1);

namespace App\Query;

use App\Enum\UserRole;
use App\Repository\ContractRepository;
use App\Repository\PaymentRepository;
use App\Repository\PlaceRepository;
use App\Repository\SelfBillingInvoiceRepository;
use App\Repository\StorageRepository;
use App\Repository\UserRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GetDashboardStatsQuery
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

    public function __invoke(GetDashboardStats $query): GetDashboardStatsResult
    {
        $now = $this->clock->now();

        $totalUsers = $this->userRepository->countTotal();
        $verifiedUsers = $this->userRepository->countVerified();
        $adminUsers = $this->userRepository->countByRole(UserRole::ADMIN->value);
        $landlordCount = $this->userRepository->countByRole(UserRole::LANDLORD->value);

        $lastMonth = $now->modify('first day of last month');
        $lastMonthYear = (int) $lastMonth->format('Y');
        $lastMonthMonth = (int) $lastMonth->format('n');

        $lastMonthRevenue = $this->paymentRepository->sumAllByPeriod($lastMonthYear, $lastMonthMonth);
        $lastMonthCommission = $this->selfBillingInvoiceRepository->sumAllCommissionsByPeriod(
            $lastMonthYear,
            $lastMonthMonth,
        );
        $expectedThisMonthRevenue = $this->contractRepository->sumExpectedRecurringAll();

        $totalPlaces = $this->placeRepository->countTotal();
        $totalStorages = $this->storageRepository->countTotal();
        $occupiedStorages = $this->storageRepository->countOccupied();
        $platformOccupancyRate = $totalStorages > 0 ? ($occupiedStorages / $totalStorages) * 100 : 0.0;
        $activeRecurringContracts = $this->contractRepository->countActiveRecurringAll();

        return new GetDashboardStatsResult(
            totalUsers: $totalUsers,
            verifiedUsers: $verifiedUsers,
            adminUsers: $adminUsers,
            unverifiedUsers: $totalUsers - $verifiedUsers,
            landlordCount: $landlordCount,
            lastMonthRevenue: $lastMonthRevenue,
            lastMonthCommission: $lastMonthCommission,
            expectedThisMonthRevenue: $expectedThisMonthRevenue,
            totalPlaces: $totalPlaces,
            totalStorages: $totalStorages,
            occupiedStorages: $occupiedStorages,
            platformOccupancyRate: $platformOccupancyRate,
            activeRecurringContracts: $activeRecurringContracts,
        );
    }
}
