<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            return response()->json([
                'status' => false,
                'code' => 'EMAIL_NOT_VERIFIED',
                'message' => 'Your email address has not been verified.',
                'email_verified' => false,
            ], 403);
        }

        return $next($request);
    }
}
