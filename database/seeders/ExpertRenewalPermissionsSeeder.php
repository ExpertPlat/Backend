<?php

namespace Database\Seeders;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class ExpertRenewalPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([AdminPermission::ExpertRenewalsViewAny, AdminPermission::ExpertRenewalsView, AdminPermission::ExpertRenewalsViewEvidence, AdminPermission::ExpertRenewalsReview] as $permission) {
            $row = Permission::findOrCreate($permission->value, 'admin');
            foreach ([AdminRole::SuperAdmin, AdminRole::KycReviewer] as $role) {
                Role::findOrCreate($role->value, 'admin')->givePermissionTo($row);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
