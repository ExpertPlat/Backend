<?php

namespace App\Http\Middleware;

use App\Models\Expert;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureExpertEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $expert = $request->user();

        if (! $expert instanceof Expert || ! $expert->hasVerifiedEmail()) {
            return response()->json([
                'status' => false,
                'message' => 'Your email address must be verified before using the expert workspace.',
            ], 403);
        }

        return $next($request);
    }
}
