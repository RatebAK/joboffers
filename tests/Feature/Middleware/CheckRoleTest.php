<?php

use App\Models\User;
use App\Http\Middleware\CheckRole;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

beforeEach(function () {
    User::truncate();
});

afterEach(function () {
    User::truncate();
});

test('admin user can access any role-protected route', function () {
    $user = User::create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => hash('sha256', 'Test@123' . 'salt'),
        'roles' => ['admin']
    ]);
    
    $token = auth()->login($user);
    auth()->setToken($token);
    auth()->authenticate();
    
    $middleware = new CheckRole();
    $request = Request::create('/test', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    
    $next = function ($request) {
        return new Response('Success', 200);
    };
    
    // Test with various role requirements
    $response = $middleware->handle($request, $next, 'employer');
    expect($response->getStatusCode())->toBe(200);
    
    $response = $middleware->handle($request, $next, 'employee');
    expect($response->getStatusCode())->toBe(200);
    
    $response = $middleware->handle($request, $next, 'employer', 'employee');
    expect($response->getStatusCode())->toBe(200);
});

test('employer user can access employer routes', function () {
    $user = User::create([
        'name' => 'Employer User',
        'email' => 'employer@example.com',
        'password' => hash('sha256', 'Test@123' . 'salt'),
        'roles' => ['employer'],
        'is_employer' => true,
    ]);
    
    $token = auth()->login($user);
    auth()->setToken($token);
    auth()->authenticate();
    
    $middleware = new CheckRole();
    $request = Request::create('/test', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    
    $next = function ($request) {
        return new Response('Success', 200);
    };
    
    $response = $middleware->handle($request, $next, 'employer');
    expect($response->getStatusCode())->toBe(200);
});

test('employee user can access employee routes', function () {
    $user = User::create([
        'name' => 'Employee User',
        'email' => 'employee@example.com',
        'password' => hash('sha256', 'Test@123' . 'salt'),
        'roles' => ['employee']
    ]);
    
    $token = auth()->login($user);
    auth()->setToken($token);
    auth()->authenticate();
    
    $middleware = new CheckRole();
    $request = Request::create('/test', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    
    $next = function ($request) {
        return new Response('Success', 200);
    };
    
    $response = $middleware->handle($request, $next, 'employee');
    expect($response->getStatusCode())->toBe(200);
});

test('user without required role is denied access', function () {
    $user = User::create([
        'name' => 'Employee User',
        'email' => 'employee@example.com',
        'password' => hash('sha256', 'Test@123' . 'salt'),
        'roles' => ['employee']
    ]);
    
    $token = auth()->login($user);
    auth()->setToken($token);
    auth()->authenticate();
    
    $middleware = new CheckRole();
    $request = Request::create('/test', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    
    $next = function ($request) {
        return new Response('Success', 200);
    };
    
    $response = $middleware->handle($request, $next, 'admin');
    expect($response->getStatusCode())->toBe(403);
    
    $responseData = json_decode($response->getContent(), true);
    expect($responseData['error'])->toBe('Forbidden');
    expect($responseData['message'])->toContain('Insufficient permissions');
});

test('middleware with multiple role requirements', function () {
    // User with employer role should access routes requiring employer OR employee
    $user = User::create([
        'name' => 'Employer User',
        'email' => 'employer@example.com',
        'password' => hash('sha256', 'Test@123' . 'salt'),
        'roles' => ['employer'],
        'is_employer' => true,
    ]);
    
    $token = auth()->login($user);
    auth()->setToken($token);
    auth()->authenticate();
    
    $middleware = new CheckRole();
    $request = Request::create('/test', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    
    $next = function ($request) {
        return new Response('Success', 200);
    };
    
    // Should succeed - user has employer role
    $response = $middleware->handle($request, $next, 'employer', 'employee');
    expect($response->getStatusCode())->toBe(200);
    
    // Should fail - user doesn't have admin role
    $response = $middleware->handle($request, $next, 'admin', 'manager');
    expect($response->getStatusCode())->toBe(403);
});

test('403 status code for authorization failures', function () {
    $user = User::create([
        'name' => 'Employee User',
        'email' => 'employee@example.com',
        'password' => hash('sha256', 'Test@123' . 'salt'),
        'roles' => ['employee']
    ]);
    
    $token = auth()->login($user);
    auth()->setToken($token);
    auth()->authenticate();
    
    $middleware = new CheckRole();
    $request = Request::create('/test', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    
    $next = function ($request) {
        return new Response('Success', 200);
    };
    
    $response = $middleware->handle($request, $next, 'admin');
    expect($response->getStatusCode())->toBe(403);
    
    $responseData = json_decode($response->getContent(), true);
    expect($responseData)->toHaveKey('error');
    expect($responseData)->toHaveKey('message');
    expect($responseData['error'])->toBe('Forbidden');
});

test('unauthenticated user is denied access', function () {
    $middleware = new CheckRole();
    $request = Request::create('/test', 'GET');
    
    $next = function ($request) {
        return new Response('Success', 200);
    };
    
    $response = $middleware->handle($request, $next, 'employee');
    expect($response->getStatusCode())->toBe(401);
    
    $responseData = json_decode($response->getContent(), true);
    expect($responseData['error'])->toBe('Unauthorized');
    expect($responseData['message'])->toBe('Authentication required');
});

test('multi-role user can access routes for any of their roles', function () {
    $user = User::create([
        'name' => 'Multi Role User',
        'email' => 'multi@example.com',
        'password' => hash('sha256', 'Test@123' . 'salt'),
        'roles' => ['employer', 'employee'],
        'is_employer' => true,
    ]);
    
    $token = auth()->login($user);
    auth()->setToken($token);
    auth()->authenticate();
    
    $middleware = new CheckRole();
    $request = Request::create('/test', 'GET');
    $request->headers->set('Authorization', 'Bearer ' . $token);
    
    $next = function ($request) {
        return new Response('Success', 200);
    };
    
    // Should access employer routes
    $response = $middleware->handle($request, $next, 'employer');
    expect($response->getStatusCode())->toBe(200);
    
    // Should access employee routes
    $response = $middleware->handle($request, $next, 'employee');
    expect($response->getStatusCode())->toBe(200);
    
    // Should not access admin routes
    $response = $middleware->handle($request, $next, 'admin');
    expect($response->getStatusCode())->toBe(403);
});