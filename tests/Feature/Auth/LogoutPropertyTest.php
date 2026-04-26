<?php

use App\Models\User;

/**
 * Property-Based Tests for Logout Functionality
 * Feature: jwt-role-based-auth
 */

test('Property 16: Logout blacklists token - Test token is blacklisted after logout', function () {
    /**
     * **Validates: Requirements 4.1**
     * Property: For any valid token, after a logout operation, that token should be added to the blacklist.
     */
    
    // Test with multiple random users and roles
    $roles = ['admin', 'employer', 'employee'];
    
    for ($i = 0; $i < 20; $i++) {
        // Create random user with random role
        $role = $roles[array_rand($roles)];
        $user = User::factory()->create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'roles' => [$role]
        ]);
        
        // Login to get a token
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password'
        ]);
        
        expect($loginResponse->status())->toBe(200);
        $token = $loginResponse->json('access_token');
        expect($token)->not()->toBeNull();
        
        // Verify token works before logout
        $profileResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/auth/profile');
        
        expect($profileResponse->status())->toBe(200);
        
        // Logout
        $logoutResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/auth/logout');
        
        expect($logoutResponse->status())->toBe(200);
        
        // Verify token is blacklisted - should fail on subsequent requests
        $profileAfterLogout = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/auth/profile');
        
        expect($profileAfterLogout->status())->toBe(401);
        
        // Clean up for next iteration
        $user->delete();
    }
});

test('Property 17: Blacklisted tokens are rejected - Test blacklisted tokens fail authentication', function () {
    /**
     * **Validates: Requirements 4.2, 10.4**
     * Property: For any token that has been blacklisted (through logout or refresh), 
     * subsequent requests using that token should be rejected with an unauthorized error.
     */
    
    $roles = ['admin', 'employer', 'employee'];
    $endpoints = ['/api/auth/profile', '/api/auth/refresh'];
    
    for ($i = 0; $i < 10; $i++) {
        // Create random user
        $role = $roles[array_rand($roles)];
        $user = User::factory()->create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'roles' => [$role]
        ]);
        
        // Login to get a token
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password'
        ]);
        
        $token = $loginResponse->json('access_token');
        
        // Logout to blacklist the token
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/auth/logout');
        
        // Test that blacklisted token is rejected on various endpoints
        foreach ($endpoints as $endpoint) {
            if ($endpoint === '/api/auth/refresh') {
                $response = $this->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                ])->postJson($endpoint);
            } else {
                $response = $this->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                ])->getJson($endpoint);
            }
            
            expect($response->status())->toBe(401, "Blacklisted token should be rejected on {$endpoint}");
        }
        
        // Clean up
        $user->delete();
    }
});

test('Property 18: Logout returns success message - Verify response format', function () {
    /**
     * **Validates: Requirements 4.3**
     * Property: For any logout request with a valid token, the response should contain a success message.
     */
    
    $roles = ['admin', 'employer', 'employee'];
    
    for ($i = 0; $i < 20; $i++) {
        // Create random user
        $role = $roles[array_rand($roles)];
        $user = User::factory()->create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'roles' => [$role]
        ]);
        
        // Login to get a token
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password'
        ]);
        
        $token = $loginResponse->json('access_token');
        
        // Test logout response
        $logoutResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/auth/logout');
        
        // Verify response format
        expect($logoutResponse->status())->toBe(200);
        expect($logoutResponse->json())->toHaveKey('message');
        expect($logoutResponse->json('message'))->toBe('User successfully signed out');
        
        // Verify response is JSON
        expect($logoutResponse->headers->get('content-type'))->toContain('application/json');
        
        // Clean up
        $user->delete();
    }
});