<?php

namespace App\Http\Middleware;

use App\Models\Expert;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsExpert
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expert = $request->user();

        if (! $expert instanceof Expert) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to access the expert area.',
            ], 403);
        }

        if (! $expert->is_active) {
            return response()->json([
                'status' => false,
                'message' => 'This expert account is currently unavailable.',
            ], 403);
        }

        return $next($request);
    }
}
