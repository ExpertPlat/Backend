<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Management\StoreAdminRequest;
use App\Http\Requests\Admin\Management\UpdateAdminRequest;
use App\Http\Requests\Admin\Management\UpdateAdminStatusRequest;
use App\Http\Resources\Admin\AdminResource;
use App\Models\Admin;
use App\Services\Admin\AdminInvitationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class AdminManagementController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Admin::class);

        $admins = Admin::query()
            ->with('roles')
            ->latest('id')
            ->paginate(25);

        return AdminResource::collection($admins);
    }

    public function show(Admin $admin): AdminResource
    {
        Gate::authorize('view', $admin);

        return new AdminResource($admin->load('roles.permissions', 'permissions'));
    }

    public function store(
        StoreAdminRequest $request,
        AdminInvitationManager $invitations,
    ): JsonResponse {
        Gate::authorize('create', Admin::class);
        $validated = $request->validated();
        $role = Role::query()
            ->where('guard_name', 'admin')
            ->findOrFail($validated['role_id']);

        $admin = DB::transaction(function () use ($validated, $role, $invitations): Admin {
            $admin = Admin::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => Str::random(64),
                'is_active' => false,
            ]);

            $admin->assignRole($role);
            $invitations->issue($admin);

            return $admin;
        });

        return $this->adminResponse(
            $admin,
            'Sub-administrator invitation created successfully.',
            201,
        );
    }

    public function update(
        UpdateAdminRequest $request,
        Admin $admin,
        AdminInvitationManager $invitations,
    ): JsonResponse {
        Gate::authorize('update', $admin);
        $validated = $request->validated();
        $emailChanged = array_key_exists('email', $validated)
            && $validated['email'] !== $admin->email;

        DB::transaction(function () use ($admin, $emailChanged, $invitations, $validated): void {
            $admin->update($validated);

            if ($emailChanged && $admin->invitation_accepted_at === null) {
                $invitations->issue($admin);
            }

            if ($emailChanged && $admin->invitation_accepted_at !== null) {
                $admin->tokens()->delete();
            }
        });

        return $this->adminResponse(
            $admin->refresh(),
            $emailChanged && $admin->invitation_accepted_at === null
                ? 'Administrator updated and a new invitation was sent successfully.'
                : 'Administrator updated successfully.',
        );
    }

    public function updateStatus(
        UpdateAdminStatusRequest $request,
        Admin $admin,
    ): JsonResponse {
        Gate::authorize('update', $admin);
        $activate = $request->boolean('is_active');

        if (! $activate && $request->user()->is($admin)) {
            abort(409, 'You cannot deactivate your own administrator account.');
        }

        if ($admin->invitation_accepted_at === null) {
            abort(409, 'A pending administrator invitation cannot be activated or deactivated.');
        }

        DB::transaction(function () use ($admin, $activate): void {
            $lockedAdmin = Admin::query()->lockForUpdate()->findOrFail($admin->getKey());

            if (! $activate
                && $lockedAdmin->is_active
                && $lockedAdmin->hasRole(AdminRole::SuperAdmin->value)) {
                $otherActiveSuperAdminExists = Admin::role(AdminRole::SuperAdmin->value)
                    ->where('admins.id', '!=', $lockedAdmin->getKey())
                    ->where('is_active', true)
                    ->exists();

                abort_unless(
                    $otherActiveSuperAdminExists,
                    409,
                    'The last active super administrator cannot be deactivated.',
                );
            }

            $lockedAdmin->forceFill(['is_active' => $activate])->save();

            if (! $activate) {
                $lockedAdmin->tokens()->delete();
            }
        });

        return $this->adminResponse(
            $admin->refresh(),
            $activate
                ? 'Administrator activated successfully.'
                : 'Administrator deactivated successfully.',
        );
    }

    public function resendInvitation(
        Admin $admin,
        AdminInvitationManager $invitations,
    ): JsonResponse {
        Gate::authorize('update', $admin);
        $invitations->issue($admin);

        return $this->adminResponse(
            $admin,
            'Administrator invitation sent successfully.',
        );
    }

    private function adminResponse(
        Admin $admin,
        string $message,
        int $status = 200,
    ): JsonResponse {
        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => [
                'admin' => new AdminResource(
                    $admin->load('roles.permissions', 'permissions'),
                ),
            ],
        ], $status);
    }
}
