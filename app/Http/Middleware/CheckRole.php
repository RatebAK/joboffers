<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        // Check if user is authenticated
        if (!auth()->check()) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Authentication required'
            ], 401);
        }

        $user = auth()->user();

        // Admin role grants universal access
        if ($user->isAdmin()) {
            return $next($request);
        }

        // Check if user has any of the required roles
        if ($user->hasAnyRole($roles)) {
            return $next($request);
        }

        // User lacks required roles
        return response()->json([
            'error' => 'Forbidden',
            'message' => 'Insufficient permissions. Required roles: ' . implode(', ', $roles)
        ], 403);
    }
}