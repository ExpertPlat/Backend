<?php

namespace App\Http\Controllers\Api\Expert\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expert\Auth\ChangePasswordRequest;
use App\Http\Requests\Expert\Auth\ForgotPasswordRequest;
use App\Http\Requests\Expert\Auth\ResetPasswordRequest;
use App\Models\Expert;
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
        Password::broker('experts')->sendResetLink([
            'email' => $request->validated('email'),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'If an expert account exists for this email, a password reset link has been sent.',
            'data' => null,
        ]);
    }

    /**
     * Reset the expert password and revoke every existing token.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker('experts')->reset(
            $request->safe()->only([
                'email',
                'token',
                'password',
                'password_confirmation',
            ]),
            function (Expert $expert, string $password): void {
                DB::transaction(function () use ($expert, $password): void {
                    $expert->forceFill([
                        'password' => Hash::make($password),
                        'remember_token' => Str::random(60),
                    ])->save();

                    $expert->tokens()->delete();
                });

                event(new PasswordReset($expert));
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
            'message' => 'Expert password reset successfully.',
            'data' => null,
        ]);
    }

    /**
     * Change the password while keeping only the current token valid.
     */
    public function update(ChangePasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        /** @var Expert $expert */
        $expert = $request->user();

        if (! Hash::check($validated['current_password'], $expert->password)) {
            return response()->json([
                'status' => false,
                'message' => 'The provided data is invalid.',
                'errors' => [
                    'current_password' => ['The current password is incorrect.'],
                ],
            ], 422);
        }

        $currentTokenId = $expert->currentAccessToken()->getKey();

        DB::transaction(function () use ($expert, $validated, $currentTokenId): void {
            $expert->forceFill([
                'password' => Hash::make($validated['password']),
                'remember_token' => Str::random(60),
            ])->save();

            $expert->tokens()->where('id', '!=', $currentTokenId)->delete();
        });

        return response()->json([
            'status' => true,
            'message' => 'Expert password changed successfully.',
            'data' => null,
        ]);
    }
}
