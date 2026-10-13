<?php

namespace Database\Seeders;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class CatalogPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->where('guard_name', 'admin')->where('name', AdminRole::SuperAdmin->value)->first();
        foreach ([AdminPermission::CatalogView, AdminPermission::CatalogManageDrafts, AdminPermission::CatalogReview, AdminPermission::CatalogPublish, AdminPermission::CatalogPause, AdminPermission::CatalogViewImpact] as $permission) {
            $row = Permission::findOrCreate($permission->value, 'admin');
            $role?->givePermissionTo($row);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
