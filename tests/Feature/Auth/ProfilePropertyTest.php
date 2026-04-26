<?php

use App\Models\User;

beforeEach(function () {
    // Clear users before each test
    User::truncate();
});

afterEach(function () {
    // Clean up after each test
    User::truncate();
});

// Feature: jwt-role-based-auth, Property 23: Profile returns user data with roles
test('profile returns user data with roles for any authenticated user', function () {
    $roles = ['admin', 'employer', 'employee'];
    
    for ($i = 0; $i < 20; $i++) {
        // Generate random user data
        $userRoles = [];
        $numRoles = rand(1, 3);
        for ($j = 0; $j < $numRoles; $j++) {
            $role = $roles[array_rand($roles)];
            if (!in_array($role, $userRoles)) {
                $userRoles[] = $role;
            }
        }
        
        $user = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => hash('sha256', fake()->password() . 'salt'),
            'roles' => $userRoles
        ]);

        // Login to get token
        $token = auth()->login($user);

        // Get profile
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token
        ])->getJson('/api/auth/profile');

        // Property: Profile should return user ID, name, email, and roles
        $response->assertStatus(200);
        $data = $response->json();
        
        expect($data)->toHaveKeys(['id', 'name', 'email', 'roles']);
        expect($data['name'])->toBe($user->name);
        expect($data['email'])->toBe($user->email);
        expect($data['roles'])->toBe($userRoles);
        
        // Clean up for next iteration
        auth()->logout();
        User::truncate();
    }
})->group('property-based');

// Feature: jwt-role-based-auth, Property 24: Unauthenticated requests are rejected
test('unauthenticated requests to profile are rejected', function () {
    for ($i = 0; $i < 10; $i++) {
        // Property: Any request without authentication should be rejected with 401
        $response = $this->getJson('/api/auth/profile');
        
        expect($response->status())->toBe(401);
    }
})->group('property-based');

// Feature: jwt-role-based-auth, Property 25: Password not included in responses
test('password is never included in profile responses', function () {
    $roles = ['admin', 'employer', 'employee'];
    
    for ($i = 0; $i < 20; $i++) {
        // Generate random user with random roles
        $userRoles = [];
        $numRoles = rand(1, 3);
        for ($j = 0; $j < $numRoles; $j++) {
            $role = $roles[array_rand($roles)];
            if (!in_array($role, $userRoles)) {
                $userRoles[] = $role;
            }
        }
        
        $password = fake()->password();
        $user = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => hash('sha256', $password . 'salt'),
            'roles' => $userRoles
        ]);

        // Login to get token
        $token = auth()->login($user);

        // Get profile
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token
        ])->getJson('/api/auth/profile');

        // Property: Password field should never be present in response
        $response->assertStatus(200);
        $data = $response->json();
        
        expect($data)->not->toHaveKey('password');
        
        // Also verify that if password was somehow included, it wouldn't match plaintext
        if (isset($data['password'])) {
            expect($data['password'])->not->toBe($password);
        }
        
        // Clean up for next iteration
        auth()->logout();
        User::truncate();
    }
})->group('property-based');