<?php

namespace Database\Seeders;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Additive upgrade: registers the Sprint 5 finance permissions and grants them to super_admin only. */
class FinancePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->where('guard_name', 'admin')->where('name', AdminRole::SuperAdmin->value)->first();
        foreach ([AdminPermission::FinanceView, AdminPermission::FinanceReconcile, AdminPermission::RefundsView, AdminPermission::RefundsApprove, AdminPermission::DisputesView, AdminPermission::StripeAccountsView] as $permission) {
            $role?->givePermissionTo(Permission::findOrCreate($permission->value, 'admin'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
