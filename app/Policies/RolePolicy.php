<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->can(AdminPermission::RolesViewAny->value);
    }

    public function view(Admin $admin, Role $role): bool
    {
        return $admin->can(AdminPermission::RolesView->value);
    }

    public function create(Admin $admin): bool
    {
        return $admin->can(AdminPermission::RolesCreate->value);
    }

    public function update(Admin $admin, Role $role): bool
    {
        if ($role->name === AdminRole::SuperAdmin->value) {
            return $admin->hasRole(AdminRole::SuperAdmin->value);
        }

        return $admin->can(AdminPermission::RolesUpdate->value);
    }

    public function delete(Admin $admin, Role $role): bool
    {
        if ($role->name === AdminRole::SuperAdmin->value) {
            return $admin->hasRole(AdminRole::SuperAdmin->value);
        }

        return $admin->can(AdminPermission::RolesDelete->value);
    }
}
