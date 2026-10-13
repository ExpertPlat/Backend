<?php

namespace Database\Seeders;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Additive upgrade: registers the Sprint 4.1 review moderation permissions and grants them to super_admin only. */
class ReviewPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::query()->where('guard_name', 'admin')->where('name', AdminRole::SuperAdmin->value)->first();
        foreach ([AdminPermission::ReviewsViewAny, AdminPermission::ReviewsModerate] as $permission) {
            $role?->givePermissionTo(Permission::findOrCreate($permission->value, 'admin'));
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
