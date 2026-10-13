<?php

namespace Database\Seeders;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PrivacyPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->where('guard_name', 'admin')->where('name', AdminRole::SuperAdmin->value)->first();
        foreach ([AdminPermission::UsersConsentMetadataView, AdminPermission::DataRequestsViewAny, AdminPermission::DataRequestsView] as $permission) {
            $record = Permission::findOrCreate($permission->value, 'admin');
            $role?->givePermissionTo($record);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
