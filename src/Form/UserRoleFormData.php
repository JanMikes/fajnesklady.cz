<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\User;
use App\Enum\UserRole;

final class UserRoleFormData
{
    public UserRole $role = UserRole::USER;

    public static function fromUser(User $user): self
    {
        $data = new self();

        // Get the highest role
        $roles = $user->getRoles();
        if (in_array(UserRole::ADMIN->value, $roles, true)) {
            $data->role = UserRole::ADMIN;
        } elseif (in_array(UserRole::LANDLORD->value, $roles, true)) {
            $data->role = UserRole::LANDLORD;
        } else {
            $data->role = UserRole::USER;
        }

        return $data;
    }
}
