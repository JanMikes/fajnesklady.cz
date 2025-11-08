<?php

declare(strict_types=1);

namespace App\Admin\Query;

use App\User\Enum\UserRole;
use App\User\Repository\UserRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class GetDashboardStatsHandler
{
    public function __construct(
        private UserRepositoryInterface $userRepository,
    ) {
    }

    public function __invoke(GetDashboardStatsQuery $query): array
    {
        $totalUsers = $this->userRepository->countTotal();
        $verifiedUsers = $this->userRepository->countVerified();
        $adminUsers = $this->userRepository->countByRole(UserRole::ADMIN->value);

        return [
            'totalUsers' => $totalUsers,
            'verifiedUsers' => $verifiedUsers,
            'adminUsers' => $adminUsers,
            'unverifiedUsers' => $totalUsers - $verifiedUsers,
        ];
    }
}
