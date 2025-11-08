<?php

declare(strict_types=1);

namespace App\Admin\Query;

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
        $allUsers = $this->userRepository->findAll();

        $totalUsers = count($allUsers);
        $verifiedUsers = count(array_filter($allUsers, fn ($user) => $user->isVerified()));
        $adminUsers = count(array_filter($allUsers, fn ($user) => in_array('ROLE_ADMIN', $user->getRoles())));

        return [
            'totalUsers' => $totalUsers,
            'verifiedUsers' => $verifiedUsers,
            'adminUsers' => $adminUsers,
            'unverifiedUsers' => $totalUsers - $verifiedUsers,
        ];
    }
}
