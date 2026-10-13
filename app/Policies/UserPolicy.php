<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\Admin;
use App\Models\User;

class UserPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->can(AdminPermission::UsersViewAny->value);
    }

    public function view(Admin $admin, User $user): bool
    {
        return $admin->can(AdminPermission::UsersView->value);
    }

    public function update(Admin $admin, User $user): bool
    {
        return $admin->can(AdminPermission::UsersUpdate->value);
    }
}
