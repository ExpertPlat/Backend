<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Authorization\StoreRoleRequest;
use App\Http\Requests\Admin\Authorization\UpdateRoleRequest;
use App\Http\Resources\Admin\AdminResource;
use App\Http\Resources\Admin\RoleResource;
use App\Models\Admin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AuthorizationController extends Controller
{
    public function roles(): JsonResponse
    {
        Gate::authorize(AdminPermission::RolesViewAny->value);

        $roles = Role::query()
            ->where('guard_name', 'admin')
            ->with('permissions:id,name,guard_name')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'guard_name' => $role->guard_name,
                'permissions' => $role->permissions->pluck('name')->sort()->values(),
                // v7 dashboard: how many administrators hold the role, and whether it is a built-in (protected) role.
                'admins_count' => \Illuminate\Support\Facades\DB::table('model_has_roles')->where('role_id', $role->id)->count(),
                'protected' => \App\Enums\AdminRole::tryFrom($role->name) !== null,
                'created_at' => $role->created_at?->toISOString(),
                'updated_at' => $role->updated_at?->toISOString(),
            ]);

        return response()->json(['status' => true, 'data' => ['roles' => $roles]]);
    }

    public function showRole(Role $role): JsonResponse
    {
        $this->ensureAdminRole($role);
        Gate::authorize('view', $role);

        return $this->roleResponse($role, 'Role retrieved successfully.');
    }

    public function storeRole(StoreRoleRequest $request): JsonResponse
    {
        Gate::authorize('create', Role::class);
        $validated = $request->validated();

        $role = DB::transaction(function () use ($validated): Role {
            $role = Role::create([
                'name' => $validated['name'],
                'guard_name' => 'admin',
            ]);
            $role->syncPermissions($validated['permissions']);

            return $role;
        });

        return $this->roleResponse($role, 'Role created successfully.', 201);
    }

    public function updateRole(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $this->ensureAdminRole($role);
        Gate::authorize('update', $role);
        $validated = $request->validated();

        if ($role->name === AdminRole::SuperAdmin->value
            && $validated['name'] !== AdminRole::SuperAdmin->value) {
            abort(409, 'The super administrator role cannot be renamed.');
        }

        DB::transaction(function () use ($role, $validated): void {
            $role->update(['name' => $validated['name']]);
            $role->syncPermissions($validated['permissions']);
        });

        return $this->roleResponse($role, 'Role updated successfully.');
    }

    public function destroyRole(Role $role): JsonResponse
    {
        $this->ensureAdminRole($role);
        Gate::authorize('delete', $role);

        if ($role->name === AdminRole::SuperAdmin->value) {
            abort(409, 'The super administrator role cannot be deleted.');
        }

        $isAssigned = DB::table('model_has_roles')
            ->where('role_id', $role->getKey())
            ->exists();

        if ($isAssigned) {
            abort(409, 'Revoke this role from all administrators before deleting it.');
        }

        $role->delete();

        return response()->json([
            'status' => true,
            'message' => 'Role deleted successfully.',
            'data' => null,
        ]);
    }

    public function permissions(): JsonResponse
    {
        Gate::authorize(AdminPermission::PermissionsViewAny->value);

        $permissions = Permission::query()
            ->where('guard_name', 'admin')
            ->orderBy('name')
            ->pluck('name');

        return response()->json(['status' => true, 'data' => ['permissions' => $permissions]]);
    }

    public function assignRole(Request $request, Admin $admin, Role $role): JsonResponse
    {
        Gate::authorize('assignRoles', $admin);
        $this->ensureAdminRole($role);
        $this->ensureSuperAdminRoleIsManagedBySuperAdmin($request, $role);

        $admin->assignRole($role);

        return $this->adminResponse($admin, 'Role assigned successfully.');
    }

    public function revokeRole(Request $request, Admin $admin, Role $role): JsonResponse
    {
        Gate::authorize('assignRoles', $admin);
        $this->ensureAdminRole($role);
        $this->ensureSuperAdminRoleIsManagedBySuperAdmin($request, $role);

        if ($role->name === AdminRole::SuperAdmin->value && $admin->hasRole($role)) {
            $otherSuperAdmins = Admin::role(AdminRole::SuperAdmin->value)
                ->where('id', '!=', $admin->getKey())
                ->where('is_active', true)
                ->exists();

            abort_unless($otherSuperAdmins, 409, 'The last active super administrator cannot lose this role.');
        }

        $admin->removeRole($role);

        return $this->adminResponse($admin, 'Role revoked successfully.');
    }

    private function ensureAdminRole(Role $role): void
    {
        abort_unless($role->guard_name === 'admin', 404);
    }

    private function ensureSuperAdminRoleIsManagedBySuperAdmin(
        Request $request,
        Role $role,
    ): void {
        if ($role->name !== AdminRole::SuperAdmin->value) {
            return;
        }

        abort_unless(
            $request->user()?->hasRole(AdminRole::SuperAdmin->value),
            403,
            'Only a super administrator can assign or revoke the super administrator role.',
        );
    }

    private function adminResponse(Admin $admin, string $message): JsonResponse
    {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => [
                'admin' => new AdminResource(
                    $admin->load('roles.permissions', 'permissions'),
                ),
            ],
        ]);
    }

    private function roleResponse(
        Role $role,
        string $message,
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => [
                'role' => new RoleResource($role->load('permissions')),
            ],
        ], $status);
    }
}
