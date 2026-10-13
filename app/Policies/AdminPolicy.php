<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;

class AdminPolicy
{
    public function viewAny(Admin $actor): bool
    {
        return $actor->can(AdminPermission::AdminsViewAny->value);
    }

    public function view(Admin $actor, Admin $target): bool
    {
        return $actor->is($target) || $actor->can(AdminPermission::AdminsView->value);
    }

    public function create(Admin $actor): bool
    {
        return $actor->can(AdminPermission::AdminsCreate->value);
    }

    public function update(Admin $actor, Admin $target): bool
    {
        if ($target->hasRole(AdminRole::SuperAdmin->value)) {
            return $actor->hasRole(AdminRole::SuperAdmin->value);
        }

        return $actor->can(AdminPermission::AdminsUpdate->value);
    }

    public function assignRoles(Admin $actor, Admin $target): bool
    {
        if ($target->hasRole(AdminRole::SuperAdmin->value)) {
            return $actor->hasRole(AdminRole::SuperAdmin->value);
        }

        return $actor->can(AdminPermission::AdminsAssignRoles->value);
    }
}
