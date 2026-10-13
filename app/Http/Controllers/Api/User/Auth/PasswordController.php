<?php

namespace App\Http\Controllers\Api\User\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\Auth\ForgotPasswordRequest;
use App\Http\Requests\User\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordController extends Controller
{
    /**
     * Send a reset notification without revealing account existence.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker('users')->sendResetLink([
            'email' => $request->validated('email'),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'If a user account exists for this email, a password reset link has been sent.',
            'data' => null,
        ]);
    }

    /**
     * Reset the regular-user password and revoke every existing token.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('users')->reset(
            $request->safe()->only([
                'email',
                'token',
                'password',
                'password_confirmation',
            ]),
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill([
                        'password' => Hash::make($password),
                        'remember_token' => Str::random(60),
                    ])->save();

                    $user->tokens()->delete();
                });

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'status' => false,
                'message' => 'The password reset token is invalid or has expired.',
                'errors' => [
                    'email' => ['The password reset token is invalid or has expired.'],
                ],
            ], 422);
        }

        return response()->json([
            'status' => true,
            'message' => 'Password reset successfully.',
            'data' => null,
        ]);
    }
}
