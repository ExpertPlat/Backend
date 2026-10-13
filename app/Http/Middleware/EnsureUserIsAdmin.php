<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if (! $admin instanceof Admin
            || ! $admin->is_active
            || $admin->invitation_accepted_at === null) {
            return response()->json([
                'status' => false,
                'message' => 'You are not authorized to access the administration area.',
            ], 403);
        }

        return $next($request);
    }
}
