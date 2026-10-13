<?php

namespace App\Policies;

use App\Enums\AdminPermission;
use App\Models\Admin;
use App\Models\Expert;

class ExpertPolicy
{
    public function viewAny(Admin $admin): bool
    {
        return $admin->can(AdminPermission::ExpertsViewAny->value);
    }

    public function view(Admin $admin, Expert $expert): bool
    {
        return $admin->can(AdminPermission::ExpertsView->value);
    }

    public function update(Admin $admin, Expert $expert): bool
    {
        return $admin->can(AdminPermission::ExpertsUpdate->value);
    }

    public function reviewKyc(Admin $admin, Expert $expert): bool
    {
        return $admin->can(AdminPermission::ExpertsReviewKyc->value);
    }
}
