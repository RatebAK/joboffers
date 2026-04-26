<?php

use App\Models\User;

beforeEach(function () {
    User::truncate();
});

afterEach(function () {
    User::truncate();
});

test('authentication errors return 401 with consistent JSON format', function () {
    // Test various authentication failure scenarios
    
    // 1. Missing token
    $response = $this->getJson('/api/auth/profile');
    $response->assertStatus(401)
        ->assertJsonStructure(['error', 'message'])
        ->assertJson(['error' => 'Unauthorized']);
    expect($response->json('message'))->toBeString();
    expect($response->json('message'))->not->toBeEmpty();
    
    // 2. Invalid credentials
    $response = $this->postJson('/api/auth/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'wrongpassword'
    ]);
    $response->assertStatus(401)
        ->assertJsonStructure(['error', 'message'])
        ->assertJson([
            'error' => 'Unauthorized',
            'message' => 'Invalid credentials'
        ]);
    
    // 3. Malformed token
    $response = $this->withHeaders([
        'Authorization' => 'Bearer invalid-token-format'
    ])->getJson('/api/auth/profile');
    $response->assertStatus(401)
        ->assertJsonStructure(['error', 'message'])
        ->assertJson(['error' => 'Unauthorized']);
    expect($response->json('message'))->toBeString();
    expect($response->json('message'))->not->toBeEmpty();
});

test('authorization errors return 403 with descriptive messages', function () {
    // Create a user with employee role
    $user = User::create([
        'name' => 'Test Employee',
        'email' => 'employee@example.com',
        'password' => hash('sha256', 'password123' . 'salt'),
        'roles' => ['employee']
    ]);
    
    $token = auth()->login($user);
    
    // Try to access admin-only route
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token
    ])->getJson('/api/admin/employers');
    
    $response->assertStatus(403)
        ->assertJsonStructure(['error', 'message'])
        ->assertJson(['error' => 'Forbidden']);
    
    // Message should be descriptive
    expect($response->json('message'))->toBeString();
    expect($response->json('message'))->toContain('permissions');
    expect($response->json('message'))->toContain('admin');
});

test('validation errors return 422 with field-specific messages', function () {
    // Test registration validation errors
    $response = $this->postJson('/api/auth/register', [
        'name' => '', // Invalid: empty name
        'email' => 'invalid-email', // Invalid: bad email format
        'password' => '123', // Invalid: too short
        'role' => 'invalid-role' // Invalid: not in allowed roles
    ]);
    
    $response->assertStatus(422);
    
    // Should have field-specific error messages
    $errors = $response->json();
    expect($errors)->toHaveKey('name');
    expect($errors)->toHaveKey('email');
    expect($errors)->toHaveKey('password');
    expect($errors)->toHaveKey('role');
    
    // Test login validation errors
    $response = $this->postJson('/api/auth/login', [
        'email' => '', // Missing email
        'password' => '' // Missing password
    ]);
    
    $response->assertStatus(422);
    $errors = $response->json();
    expect($errors)->toHaveKey('email');
    expect($errors)->toHaveKey('password');
});

test('all error responses are in JSON format', function () {
    // Test that all API error responses return JSON, not HTML
    
    $testCases = [
        // Authentication error
        ['method' => 'get', 'url' => '/api/auth/profile', 'expectedStatus' => 401],
        // Authorization error (need to create user first)
        // Validation error
        ['method' => 'post', 'url' => '/api/auth/login', 'data' => [], 'expectedStatus' => 422],
    ];
    
    foreach ($testCases as $testCase) {
        $response = $this->{$testCase['method'] . 'Json'}(
            $testCase['url'], 
            $testCase['data'] ?? []
        );
        
        $response->assertStatus($testCase['expectedStatus']);
        
        // Verify response is JSON
        expect($response->headers->get('content-type'))->toContain('application/json');
        
        // Verify response can be decoded as JSON
        $json = $response->json();
        expect($json)->toBeArray();
        expect($json)->not->toBeEmpty();
    }
    
    // Test authorization error separately (needs authenticated user)
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => hash('sha256', 'password123' . 'salt'),
        'roles' => ['employee']
    ]);
    
    $token = auth()->login($user);
    
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token
    ])->getJson('/api/admin/employers');
    
    $response->assertStatus(403);
    expect($response->headers->get('content-type'))->toContain('application/json');
    $json = $response->json();
    expect($json)->toBeArray();
    expect($json)->toHaveKey('error');
    expect($json)->toHaveKey('message');
});

test('error messages are descriptive and user-friendly', function () {
    // Test that error messages provide helpful information
    
    // 1. Authentication token required
    $response = $this->getJson('/api/auth/profile');
    $response->assertStatus(401);
    $message = $response->json('message');
    expect($message)->toBeString();
    expect(strlen($message))->toBeGreaterThan(10); // Should be descriptive
    
    // 2. Invalid credentials
    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => 'wrongpassword'
    ]);
    $response->assertStatus(401);
    expect($response->json('message'))->toBe('Invalid credentials');
    
    // 3. Insufficient permissions
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => hash('sha256', 'password123' . 'salt'),
        'roles' => ['employee']
    ]);
    
    $token = auth()->login($user);
    
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token
    ])->getJson('/api/admin/employers');
    
    $response->assertStatus(403);
    $message = $response->json('message');
    expect($message)->toContain('permissions');
    expect($message)->toContain('admin');
    
    // 4. Validation errors should be specific
    $response = $this->postJson('/api/auth/register', [
        'email' => 'invalid-email'
    ]);
    $response->assertStatus(422);
    $errors = $response->json();
    expect($errors['email'][0])->toContain('email');
});