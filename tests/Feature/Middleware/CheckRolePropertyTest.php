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

// Property 19: Role-based access control - Test access granted/denied based on roles
test('Property 19: Role-based access control', function () {
    /**
     * **Validates: Requirements 5.1, 5.2, 6.1, 6.2, 7.1, 7.2, 6.5, 7.5**
     * Property: For any user and any role-protected endpoint, access should be granted 
     * if and only if the user has at least one of the required roles for that endpoint.
     */
    
    $allRoles = ['admin', 'employer', 'employee'];
    $middleware = new CheckRole();
    
    for ($i = 0; $i < 20; $i++) {
        // Create user with random roles (1-3 roles)
        $userRoles = fake()->randomElements($allRoles, fake()->numberBetween(1, 3));
        $user = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => hash('sha256', 'Test@123' . 'salt'),
            'roles' => $userRoles,
            // Set is_employer=true if user has employer role so approval check passes
            'is_employer' => in_array('employer', $userRoles) ? true : false,
        ]);
        
        // Test with random required roles (1-2 roles)
        $requiredRoles = fake()->randomElements($allRoles, fake()->numberBetween(1, 2));
        
        // Login user to authenticate
        $token = auth()->login($user);
        
        // Create mock request with proper authentication
        $request = Request::create('/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);
        
        // Set the authenticated user in the request
        auth()->setToken($token);
        auth()->authenticate();
        
        // Mock next closure
        $next = function ($request) {
            return new Response('Success', 200);
        };
        
        // Test middleware
        $response = $middleware->handle($request, $next, ...$requiredRoles);
        
        // Property: Access should be granted if user has any required role OR user is admin
        $hasRequiredRole = !empty(array_intersect($userRoles, $requiredRoles));
        $isAdmin = in_array('admin', $userRoles);
        
        if ($hasRequiredRole || $isAdmin) {
            expect($response->getStatusCode())->toBe(200);
        } else {
            expect($response->getStatusCode())->toBe(403);
            $responseData = json_decode($response->getContent(), true);
            expect($responseData)->toHaveKey('error');
            expect($responseData['error'])->toBe('Forbidden');
        }
        
        // Logout for next iteration
        auth()->logout();
        User::truncate();
    }
})->group('property-tests');

// Property 20: Admin universal access - Test admin can access all protected endpoints
test('Property 20: Admin universal access', function () {
    /**
     * **Validates: Requirements 6.5, 7.5**
     * Property: For any user with the admin role, they should be able to access 
     * all role-protected endpoints regardless of other role requirements.
     */
    
    $allRoles = ['admin', 'employer', 'employee'];
    $middleware = new CheckRole();
    
    for ($i = 0; $i < 20; $i++) {
        // Create admin user (may have additional roles)
        $userRoles = ['admin'];
        if (fake()->boolean(50)) {
            // 50% chance to add additional roles
            $additionalRoles = fake()->randomElements(['employer', 'employee'], fake()->numberBetween(1, 2));
            $userRoles = array_merge($userRoles, $additionalRoles);
        }
        
        $user = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => hash('sha256', 'Test@123' . 'salt'),
            'roles' => $userRoles
        ]);
        
        // Test with random required roles (admin should access all)
        $requiredRoles = fake()->randomElements($allRoles, fake()->numberBetween(1, 3));
        
        // Login user to authenticate
        $token = auth()->login($user);
        
        // Create mock request with proper authentication
        $request = Request::create('/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);
        
        // Set the authenticated user in the request
        auth()->setToken($token);
        auth()->authenticate();
        
        // Mock next closure
        $next = function ($request) {
            return new Response('Success', 200);
        };
        
        // Test middleware
        $response = $middleware->handle($request, $next, ...$requiredRoles);
        
        // Property: Admin should always have access regardless of required roles
        expect($response->getStatusCode())->toBe(200);
        
        // Logout for next iteration
        auth()->logout();
        User::truncate();
    }
})->group('property-tests');