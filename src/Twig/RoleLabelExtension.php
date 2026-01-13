<?php

declare(strict_types=1);

namespace App\Twig;

use App\Enum\UserRole;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class RoleLabelExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('role_label', $this->roleLabel(...)),
        ];
    }

    public function roleLabel(string $role): string
    {
        $userRole = UserRole::tryFrom($role);

        if (null === $userRole) {
            return $role;
        }

        return $userRole->label();
    }
}
