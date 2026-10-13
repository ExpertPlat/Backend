<?php

namespace App\Http\Controllers\Api\User\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Send a fresh verification notification when required.
     */
    public function send(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'status' => true,
                'message' => 'Email address is already verified.',
                'data' => ['email_verified' => true],
            ]);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'status' => true,
            'message' => 'Email verification link sent successfully.',
            'data' => ['email_verified' => false],
        ]);
    }

    /**
     * Verify a regular-user email address through a signed URL.
     */
    public function verify(int $id, string $hash): JsonResponse
    {
        $user = User::query()->find($id);

        if (! $user || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return response()->json([
                'status' => false,
                'message' => 'The email verification link is invalid or has expired.',
            ], 403);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'status' => true,
                'message' => 'Email address is already verified.',
                'data' => ['email_verified' => true],
            ]);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json([
            'status' => true,
            'message' => 'Email address verified successfully.',
            'data' => ['email_verified' => true],
        ]);
    }
}
