<?php

use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

beforeEach(function () {
    User::truncate();
});

afterEach(function () {
    User::truncate();
});

// **Property 13: Token refresh generates new valid token** - Test refresh with random valid tokens
test('Property 13: Token refresh generates new valid token', function () {
    for ($i = 0; $i < 20; $i++) {
        // Create random test user
        $password = 'Test@123';
        $user = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => hash('sha256', $password . 'salt'),
            'roles' => [fake()->randomElement(['admin', 'employer', 'employee'])]
        ]);
        
        // Login to get initial token
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password
        ]);
        
        $loginResponse->assertStatus(200);
        $originalToken = $loginResponse->json('access_token');
        
        // Refresh the token
        $refreshResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $originalToken
        ])->postJson('/api/auth/refresh');
        
        $refreshResponse->assertStatus(200);
        $newToken = $refreshResponse->json('access_token');
        
        // Property: New token should be different from original
        expect($newToken)->not->toBe($originalToken);
        
        // Property: New token should be valid and parseable
        $payload = JWTAuth::setToken($newToken)->getPayload();
        expect($payload->get('sub'))->toBe($user->id);
        
        // Property: Response should have correct structure
        $refreshResponse->assertJsonStructure([
            'access_token',
            'token_type',
            'expires_in',
            'user'
        ]);
        
        // Clean up for next iteration
        User::truncate();
    }
})->group('property-based-test');

// **Property 14: Refresh invalidates old token** - Test old token becomes blacklisted
test('Property 14: Refresh invalidates old token', function () {
    for ($i = 0; $i < 20; $i++) {
        // Create random test user
        $password = 'Test@123';
        $user = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => hash('sha256', $password . 'salt'),
            'roles' => [fake()->randomElement(['admin', 'employer', 'employee'])]
        ]);
        
        // Login to get initial token
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password
        ]);
        
        $originalToken = $loginResponse->json('access_token');
        
        // Refresh the token
        $refreshResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $originalToken
        ])->postJson('/api/auth/refresh');
        
        $refreshResponse->assertStatus(200);
        
        // Property: Old token should be blacklisted and rejected
        $profileResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $originalToken
        ])->getJson('/api/auth/profile');
        
        $profileResponse->assertStatus(401);
        
        // Property: Old token should throw blacklist exception at JWT level
        try {
            JWTAuth::setToken($originalToken)->getPayload();
            $this->fail('Token should be blacklisted');
        } catch (\PHPOpenSourceSaver\JWTAuth\Exceptions\TokenBlacklistedException $e) {
            expect($e->getMessage())->toContain('blacklisted');
        }
        
        // Clean up for next iteration
        User::truncate();
    }
})->group('property-based-test');

// **Property 15: Refresh preserves user identity** - Verify user ID remains same
test('Property 15: Refresh preserves user identity', function () {
    for ($i = 0; $i < 20; $i++) {
        // Create random test user with random roles
        $password = 'Test@123';
        $roles = fake()->randomElements(['admin', 'employer', 'employee'], fake()->numberBetween(1, 3));
        $user = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => hash('sha256', $password . 'salt'),
            'roles' => $roles
        ]);
        
        // Login to get initial token
        $loginResponse = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password
        ]);
        
        $originalToken = $loginResponse->json('access_token');
        $originalUser = $loginResponse->json('user');
        
        // Refresh the token
        $refreshResponse = $this->withHeaders([
            'Authorization' => 'Bearer ' . $originalToken
        ])->postJson('/api/auth/refresh');
        
        $refreshResponse->assertStatus(200);
        $newUser = $refreshResponse->json('user');
        
        // Property: User identity should be preserved
        expect($newUser['id'])->toBe($originalUser['id']);
        expect($newUser['name'])->toBe($originalUser['name']);
        expect($newUser['email'])->toBe($originalUser['email']);
        expect($newUser['roles'])->toBe($originalUser['roles']);
        
        // Property: JWT payload should contain same user ID
        $newToken = $refreshResponse->json('access_token');
        $payload = JWTAuth::setToken($newToken)->getPayload();
        expect($payload->get('sub'))->toBe($user->id);
        
        // Clean up for next iteration
        User::truncate();
    }
})->group('property-based-test');