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
            // Employers additionally require admin approval, except for
            // the apply/status routes which are accessible pre-approval.
            if (in_array('employer', $roles) && $user->hasRole('employer') && !$user->isAdmin()) {
                $path = $request->path();
                $preApprovalRoutes = ['api/employer/apply', 'api/employer/status'];
                if (!in_array($path, $preApprovalRoutes) && !$user->is_employer) {
                    return response()->json([
                        'error'   => 'Forbidden',
                        'message' => 'Your employer account is pending admin approval.',
                    ], 403);
                }
            }
            return $next($request);
        }

        // User lacks required roles
        return response()->json([
            'error' => 'Forbidden',
            'message' => 'Insufficient permissions. Required roles: ' . implode(', ', $roles)
        ], 403);
    }
}