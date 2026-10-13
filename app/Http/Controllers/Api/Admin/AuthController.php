<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\AcceptInvitationRequest;
use App\Http\Requests\Admin\Auth\LoginRequest;
use App\Http\Resources\Admin\AdminResource;
use App\Models\Admin;
use App\Services\Admin\AdminInvitationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function acceptInvitation(
        AcceptInvitationRequest $request,
        AdminInvitationManager $invitations,
    ): JsonResponse {
        $validated = $request->validated();
        $invitations->accept(
            $validated['email'],
            $validated['token'],
            $validated['password'],
        );

        return response()->json([
            'status' => true,
            'message' => 'Administrator invitation accepted successfully. You can now sign in.',
            'data' => null,
        ]);
    }

    /**
     * Authenticate an administrator and issue a restricted access token.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $admin = Admin::query()
            ->select([
                'id',
                'name',
                'email',
                'password',
                'is_active',
                'invitation_accepted_at',
                'last_login_at',
                'created_at',
                'updated_at',
            ])
            ->where('email', $validated['email'])
            ->first();

        if (! $admin
            || ! Hash::check($validated['password'], $admin->password)
            || ! $admin->is_active
            || $admin->invitation_accepted_at === null) {
            return response()->json([
                'status' => false,
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        $admin->forceFill(['last_login_at' => now()])->save();

        $plainTextToken = $admin
            ->createToken($this->tokenName($validated), [Admin::ACCESS_ABILITY])
            ->plainTextToken;

        $admin->load('roles.permissions', 'permissions');

        return response()->json([
            'status' => true,
            'message' => 'Administrator logged in successfully.',
            'data' => [
                'admin' => new AdminResource($admin),
                'token' => $plainTextToken,
                'token_type' => 'Bearer',
            ],
        ]);
    }

    /**
     * Return the authenticated administrator.
     */
    public function me(Request $request): JsonResponse
    {
        $request->user()->load('roles.permissions', 'permissions');

        return response()->json([
            'status' => true,
            'message' => 'Administrator retrieved successfully.',
            'data' => [
                'token_type' => 'Bearer',
                'admin' => new AdminResource($request->user()),
            ],
        ]);
    }

    /**
     * Revoke the token used for the current request.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'status' => true,
            'message' => 'Administrator logged out successfully.',
            'data' => null,
        ]);
    }

    /**
     * Revoke every token owned by the authenticated administrator.
     */
    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json([
            'status' => true,
            'message' => 'Administrator logged out from all devices successfully.',
            'data' => null,
        ]);
    }

    /**
     * Get the token name supplied by the client or a safe default.
     *
     * @param  array<string, mixed>  $validated
     */
    private function tokenName(array $validated): string
    {
        return $validated['device_name'] ?? 'Admin API Token';
    }
}
