<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Register JWT middleware aliases
        $middleware->alias([
            'jwt.auth' => \PHPOpenSourceSaver\JWTAuth\Http\Middleware\Authenticate::class,
            'jwt.refresh' => \PHPOpenSourceSaver\JWTAuth\Http\Middleware\RefreshToken::class,
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Handle Laravel's AuthenticationException (thrown by auth middleware)
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => 'Unauthorized',
                    'message' => $e->getMessage() ?: 'Authentication required'
                ], 401);
            }
        });

        // Handle Symfony's UnauthorizedHttpException (thrown by JWT middleware)
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                $message = $e->getMessage();
                
                // Provide more descriptive messages for common JWT errors
                if (str_contains($message, 'Token not provided')) {
                    $message = 'Authentication token is required';
                } elseif (str_contains($message, 'User not found')) {
                    $message = 'Invalid authentication token';
                } elseif (str_contains($message, 'Token has expired')) {
                    $message = 'Authentication token has expired';
                } elseif (str_contains($message, 'Token Signature could not be verified')) {
                    $message = 'Invalid authentication token signature';
                } elseif (str_contains($message, 'Wrong number of segments')) {
                    $message = 'Malformed authentication token';
                } elseif (str_contains($message, 'blacklisted')) {
                    $message = 'Authentication token has been invalidated';
                } elseif (empty($message) || $message === 'jwt-auth') {
                    $message = 'Authentication failed';
                }

                return response()->json([
                    'error' => 'Unauthorized',
                    'message' => $message
                ], 401);
            }
        });

        // Handle validation errors to ensure consistent 422 responses
        $exceptions->render(function (\Illuminate\Validation\ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json($e->errors(), 422);
            }
        });

        // Handle authorization errors (403 Forbidden)
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error' => 'Forbidden',
                    'message' => $e->getMessage() ?: 'Access denied'
                ], 403);
            }
        });
    })->create();