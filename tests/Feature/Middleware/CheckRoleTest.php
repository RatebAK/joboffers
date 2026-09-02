<?php

// =============================================================================
// CheckRoleTest — unit-level tests for the CheckRole middleware.
//
// Exercises the middleware directly (not through the router) to assert the
// grant/deny logic and the shape of its 401/403 responses.
// =============================================================================

use App\Http\Middleware\CheckRole;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Authenticate as a fresh user of the given roles and return the middleware + request. */
function checkRoleContext(array $roles): array
{
    $user  = createUser('employee', ['roles' => $roles, 'is_employer' => in_array('employer', $roles)]);
    $token = auth('api')->login($user);
    auth('api')->setToken($token)->authenticate();

    $request = Request::create('/test', 'GET');
    $request->headers->set('Authorization', "Bearer {$token}");

    return [new CheckRole(), $request];
}

$pass = fn () => new Response('Success', 200);

test('an employee is granted access to an employee route', function () use ($pass) {
    [$middleware, $request] = checkRoleContext(['employee']);

    expect($middleware->handle($request, $pass, 'employee')->getStatusCode())->toBe(200);
});

test('access is granted when the user matches any of several required roles', function () use ($pass) {
    [$middleware, $request] = checkRoleContext(['employer']);

    expect($middleware->handle($request, $pass, 'employer', 'employee')->getStatusCode())->toBe(200);
});

test('an admin can access any role-protected route', function () use ($pass) {
    [$middleware, $request] = checkRoleContext(['admin']);

    expect($middleware->handle($request, $pass, 'employer')->getStatusCode())->toBe(200)
        ->and($middleware->handle($request, $pass, 'employee')->getStatusCode())->toBe(200);
});

test('a multi-role user is denied a route for a role they lack', function () use ($pass) {
    [$middleware, $request] = checkRoleContext(['employer', 'employee']);

    expect($middleware->handle($request, $pass, 'admin')->getStatusCode())->toBe(403);
});

test('a user without the required role gets a 403 with a Forbidden error', function () use ($pass) {
    [$middleware, $request] = checkRoleContext(['employee']);

    $response = $middleware->handle($request, $pass, 'admin');
    $body     = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(403)
        ->and($body['error'])->toBe('Forbidden')
        ->and($body['message'])->toContain('Insufficient permissions');
});

test('an unauthenticated request gets a 401 with an Unauthorized error', function () use ($pass) {
    $response = (new CheckRole())->handle(Request::create('/test', 'GET'), $pass, 'employee');
    $body     = json_decode($response->getContent(), true);

    expect($response->getStatusCode())->toBe(401)
        ->and($body['error'])->toBe('Unauthorized')
        ->and($body['message'])->toBe('Authentication required');
});
