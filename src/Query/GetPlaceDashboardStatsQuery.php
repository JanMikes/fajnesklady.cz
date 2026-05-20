<?php

declare(strict_types=1);

namespace App\Query;

use App\Repository\ContractRepository;
use App\Repository\PaymentRepository;
use App\Repository\PlaceRepository;
use App\Repository\StorageRepository;
use App\Repository\StorageTypeRepository;
use App\Repository\UserRepository;
use App\Service\Overdue\OverdueChecker;
use App\Value\OverdueSummary;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GetPlaceDashboardStatsQuery
{
    public function __construct(
        private PlaceRepository $placeRepository,
        private UserRepository $userRepository,
        private StorageRepository $storageRepository,
        private StorageTypeRepository $storageTypeRepository,
        private ContractRepository $contractRepository,
        private PaymentRepository $paymentRepository,
        private OverdueChecker $overdueChecker,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(GetPlaceDashboardStats $query): GetPlaceDashboardStatsResult
    {
        $place = $this->placeRepository->get($query->placeId);
        $owner = null !== $query->landlordId
            ? $this->userRepository->get($query->landlordId)
            : null;
        $now = $this->clock->now();

        $totalStorages = $this->storageRepository->countAtPlace($place, $owner);
        $occupiedStorages = $this->storageRepository->countOccupiedAtPlace($place, $owner);
        $availableStorages = $this->storageRepository->countAvailableAtPlace($place, $owner);
        $blockedStorages = $this->storageRepository->countBlockedAtPlace($place, $owner);
        $occupancyRate = $totalStorages > 0
            ? ($occupiedStorages / $totalStorages) * 100
            : 0.0;

        $lastMonth = $now->modify('first day of last month');
        $lastMonthRevenue = $this->paymentRepository->sumAtPlaceAndPeriod(
            $place,
            (int) $lastMonth->format('Y'),
            (int) $lastMonth->format('n'),
            $owner,
        );

        $expectedThisMonthRevenue = $this->contractRepository->sumExpectedRecurringAtPlace($place, $owner);
        $expectedYearlyRevenue = $this->contractRepository->sumExpectedYearlyAtPlace($place, $owner);
        $activeContractsCount = $this->contractRepository->countActiveContractsAtPlace($place, $owner, $now);
        $activeRecurringContracts = $this->contractRepository->countActiveRecurringAtPlace($place, $owner);

        $overdue = null === $owner
            ? $this->overdueChecker->summariseForPlace($now, $place)
            : new OverdueSummary(count: 0, totalAmount: 0, top: []);

        $missingOperatingRules = null === $owner && null === $place->operatingRulesPath;
        $missingInstructions = null === $owner && null === $place->instructionsPath;
        $missingMap = null === $owner && null === $place->mapImagePath;
        $missingStorageTypes = null === $owner
            && 0 === $this->storageTypeRepository->countByPlace($place);
        $missingLockCodes = null === $owner
            && $place->storageCodesEnabled
            && [] === $this->storageRepository->findActiveLockCodesByPlace($place);

        $hasCoOwners = null !== $owner
            && $this->storageRepository->hasCoOwners($place, $owner);

        return new GetPlaceDashboardStatsResult(
            totalStorages: $totalStorages,
            occupiedStorages: $occupiedStorages,
            availableStorages: $availableStorages,
            blockedStorages: $blockedStorages,
            occupancyRate: $occupancyRate,
            lastMonthRevenue: $lastMonthRevenue,
            expectedThisMonthRevenue: $expectedThisMonthRevenue,
            expectedYearlyRevenue: $expectedYearlyRevenue,
            activeContractsCount: $activeContractsCount,
            activeRecurringContracts: $activeRecurringContracts,
            overdueCount: $overdue->count,
            overdueAmount: $overdue->totalAmount,
            overdueTop: $overdue->top,
            missingOperatingRules: $missingOperatingRules,
            missingInstructions: $missingInstructions,
            missingMap: $missingMap,
            missingStorageTypes: $missingStorageTypes,
            missingLockCodes: $missingLockCodes,
            hasCoOwners: $hasCoOwners,
        );
    }
}
