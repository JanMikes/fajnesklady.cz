<?php

declare(strict_types=1);

namespace App\Query;

use App\Enum\UserRole;
use App\Repository\UserRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GetDashboardStatsQuery
{
    public function __construct(
        private UserRepository $userRepository,
    ) {
    }

    public function __invoke(GetDashboardStats $query): GetDashboardStatsResult
    {
        $totalUsers = $this->userRepository->countTotal();
        $verifiedUsers = $this->userRepository->countVerified();
        $adminUsers = $this->userRepository->countByRole(UserRole::ADMIN->value);

        return new GetDashboardStatsResult(
            totalUsers: $totalUsers,
            verifiedUsers: $verifiedUsers,
            adminUsers: $adminUsers,
            unverifiedUsers: $totalUsers - $verifiedUsers,
        );
    }
}
