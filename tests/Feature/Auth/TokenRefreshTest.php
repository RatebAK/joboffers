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

test('refresh endpoint generates new token', function () {
    // Create test user with fallback hashing
    $password = 'Test@123';
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => hash('sha256', $password . 'salt'),
        'roles' => ['employee']
    ]);
    
    // Login to get initial token
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => $password
    ]);
    
    $loginResponse->assertStatus(200);
    $originalToken = $loginResponse->json('access_token');
    
    // Wait a moment to ensure different timestamps
    sleep(1);
    
    // Refresh the token
    $refreshResponse = $this->withHeaders([
        'Authorization' => 'Bearer ' . $originalToken
    ])->postJson('/api/auth/refresh');
    
    $refreshResponse->assertStatus(200);
    $newToken = $refreshResponse->json('access_token');
    
    // Verify new token is different from original
    expect($newToken)->not->toBe($originalToken);
    
    // Verify response structure
    $refreshResponse->assertJsonStructure([
        'access_token',
        'token_type',
        'expires_in',
        'user'
    ]);
    
    // Verify token type is bearer
    expect($refreshResponse->json('token_type'))->toBe('bearer');
});

test('old token is blacklisted after refresh', function () {
    // Create test user
    $password = 'Test@123';
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => hash('sha256', $password . 'salt'),
        'roles' => ['employee']
    ]);
    
    // Login to get initial token
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => $password
    ]);
    
    $originalToken = $loginResponse->json('access_token');
    
    // Verify original token works
    $profileResponse1 = $this->withHeaders([
        'Authorization' => 'Bearer ' . $originalToken
    ])->getJson('/api/auth/profile');
    $profileResponse1->assertStatus(200);
    
    // Refresh the token
    $refreshResponse = $this->withHeaders([
        'Authorization' => 'Bearer ' . $originalToken
    ])->postJson('/api/auth/refresh');
    
    $refreshResponse->assertStatus(200);
    
    // Try to use the old token - should fail
    $profileResponse = $this->withHeaders([
        'Authorization' => 'Bearer ' . $originalToken
    ])->getJson('/api/auth/profile');
    
    $profileResponse->assertStatus(401);
});

test('user identity is preserved in new token', function () {
    // Create test user
    $password = 'Test@123';
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => hash('sha256', $password . 'salt'),
        'roles' => ['employee']
    ]);
    
    // Login to get initial token
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
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
    
    // Verify user data is preserved
    expect($newUser['id'])->toBe($originalUser['id']);
    expect($newUser['name'])->toBe($originalUser['name']);
    expect($newUser['email'])->toBe($originalUser['email']);
    expect($newUser['roles'])->toBe($originalUser['roles']);
    
    // Verify user data structure
    $refreshResponse->assertJsonStructure([
        'user' => [
            'id',
            'name',
            'email',
            'roles'
        ]
    ]);
});

test('refreshed token has new expiration time', function () {
    // Create test user
    $password = 'Test@123';
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => hash('sha256', $password . 'salt'),
        'roles' => ['employee']
    ]);
    
    // Login to get initial token
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => $password
    ]);
    
    $originalToken = $loginResponse->json('access_token');
    $originalExpiresIn = $loginResponse->json('expires_in');
    
    // Wait a moment to ensure different timestamps
    sleep(2);
    
    // Refresh the token
    $refreshResponse = $this->withHeaders([
        'Authorization' => 'Bearer ' . $originalToken
    ])->postJson('/api/auth/refresh');
    
    $refreshResponse->assertStatus(200);
    $newExpiresIn = $refreshResponse->json('expires_in');
    
    // The new token should have a fresh expiration time
    // Since we waited 2 seconds, the new expires_in should be greater than original - 2
    expect($newExpiresIn)->toBeGreaterThan($originalExpiresIn - 2);
    
    // Verify the expires_in matches JWT TTL configuration (60 minutes = 3600 seconds)
    expect($newExpiresIn)->toBe(3600);
});

test('refresh with invalid token fails', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer invalid-token'
    ])->postJson('/api/auth/refresh');
    
    $response->assertStatus(401);
});

test('refresh without token fails', function () {
    $response = $this->postJson('/api/auth/refresh');
    
    $response->assertStatus(401);
});