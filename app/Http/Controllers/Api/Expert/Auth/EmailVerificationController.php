<?php

namespace App\Http\Controllers\Api\Expert\Auth;

use App\Http\Controllers\Controller;
use App\Models\Expert;
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
        /** @var Expert $expert */
        $expert = $request->user();

        if ($expert->hasVerifiedEmail()) {
            return response()->json([
                'status' => true,
                'message' => 'Email address is already verified.',
                'data' => ['email_verified' => true],
            ]);
        }

        $expert->sendEmailVerificationNotification();

        return response()->json([
            'status' => true,
            'message' => 'Email verification link sent successfully.',
            'data' => ['email_verified' => false],
        ]);
    }

    /**
     * Verify an expert email address through a signed URL.
     */
    public function verify(int $id, string $hash): JsonResponse
    {
        $expert = Expert::query()->find($id);

        if (! $expert || ! hash_equals(sha1($expert->getEmailForVerification()), $hash)) {
            return response()->json([
                'status' => false,
                'message' => 'The email verification link is invalid or has expired.',
            ], 403);
        }

        if ($expert->hasVerifiedEmail()) {
            return response()->json([
                'status' => true,
                'message' => 'Email address is already verified.',
                'data' => ['email_verified' => true],
            ]);
        }

        if ($expert->markEmailAsVerified()) {
            event(new Verified($expert));
        }

        return response()->json([
            'status' => true,
            'message' => 'Email address verified successfully.',
            'data' => ['email_verified' => true],
        ]);
    }
}
