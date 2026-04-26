<?php

use App\Models\User;

beforeEach(function () {
    User::truncate();
});

afterEach(function () {
    User::truncate();
});

test('401 for invalid credentials during login', function () {
    // Test specific case of wrong password
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => hash('sha256', 'correct-password' . 'salt'),
        'roles' => ['employee']
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => 'wrong-password'
    ]);

    $response->assertStatus(401)
        ->assertJson([
            'error' => 'Unauthorized',
            'message' => 'Invalid credentials'
        ]);
});

test('401 for non-existent email during login', function () {
    $response = $this->postJson('/api/auth/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'any-password'
    ]);

    $response->assertStatus(401)
        ->assertJson([
            'error' => 'Unauthorized',
            'message' => 'Invalid credentials'
        ]);
});

test('401 for missing authentication token', function () {
    $response = $this->getJson('/api/auth/profile');

    $response->assertStatus(401)
        ->assertJsonStructure(['error', 'message'])
        ->assertJson(['error' => 'Unauthorized']);
    
    // Message should indicate token is required
    expect($response->json('message'))->toContain('token');
});

test('401 for malformed authentication token', function () {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer invalid-malformed-token'
    ])->getJson('/api/auth/profile');

    $response->assertStatus(401)
        ->assertJsonStructure(['error', 'message'])
        ->assertJson(['error' => 'Unauthorized']);
    
    // Should have descriptive message
    expect($response->json('message'))->toBeString();
    expect(strlen($response->json('message')))->toBeGreaterThan(5);
});

test('401 for expired authentication token', function () {
    // Create user and token, then invalidate it
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => hash('sha256', 'password123' . 'salt'),
        'roles' => ['employee']
    ]);

    $token = auth()->login($user);
    
    // Logout to blacklist the token (simulates expiration)
    auth()->logout();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token
    ])->getJson('/api/auth/profile');

    $response->assertStatus(401)
        ->assertJsonStructure(['error', 'message'])
        ->assertJson(['error' => 'Unauthorized']);
});

test('403 for insufficient permissions - employee accessing admin route', function () {
    $user = User::create([
        'name' => 'Employee User',
        'email' => 'employee@example.com',
        'password' => hash('sha256', 'password123' . 'salt'),
        'roles' => ['employee']
    ]);

    $token = auth()->login($user);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token
    ])->getJson('/api/admin/employers');

    $response->assertStatus(403)
        ->assertJson([
            'error' => 'Forbidden',
            'message' => 'Insufficient permissions. Required roles: admin'
        ]);
});

test('403 for insufficient permissions - employee accessing employer route', function () {
    $user = User::create([
        'name' => 'Employee User',
        'email' => 'employee@example.com',
        'password' => hash('sha256', 'password123' . 'salt'),
        'roles' => ['employee']
    ]);

    $token = auth()->login($user);

    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token
    ])->getJson('/api/employer/status');

    $response->assertStatus(403)
        ->assertJson([
            'error' => 'Forbidden',
            'message' => 'Insufficient permissions. Required roles: employer'
        ]);
});

test('422 for validation errors during registration', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => '', // Required field missing
        'email' => 'invalid-email-format', // Invalid email
        'password' => '123', // Too short
        'password_confirmation' => '456', // Doesn't match
        'role' => 'invalid-role' // Not in allowed roles
    ]);

    $response->assertStatus(422);
    
    $errors = $response->json();
    expect($errors)->toHaveKey('name');
    expect($errors)->toHaveKey('email');
    expect($errors)->toHaveKey('password');
    expect($errors)->toHaveKey('role');
    
    // Each error should be an array of messages
    expect($errors['name'])->toBeArray();
    expect($errors['email'])->toBeArray();
    expect($errors['password'])->toBeArray();
    expect($errors['role'])->toBeArray();
    
    // Messages should be descriptive
    expect($errors['name'][0])->toContain('required');
    expect($errors['email'][0])->toContain('email');
    expect($errors['password'][0])->toContain('password');
    expect($errors['role'][0])->toContain('role');
});

test('422 for validation errors during login', function () {
    $response = $this->postJson('/api/auth/login', [
        'email' => 'not-an-email', // Invalid email format
        'password' => '12' // Too short
    ]);

    $response->assertStatus(422);
    
    $errors = $response->json();
    expect($errors)->toHaveKey('email');
    expect($errors)->toHaveKey('password');
    
    expect($errors['email'][0])->toContain('email');
    expect($errors['password'][0])->toContain('least');
});

test('error response JSON structure is consistent', function () {
    // Test that all error types follow consistent JSON structure
    
    // Authentication error (401)
    $response = $this->getJson('/api/auth/profile');
    $response->assertStatus(401);
    $json = $response->json();
    expect($json)->toHaveKey('error');
    expect($json)->toHaveKey('message');
    expect($json['error'])->toBe('Unauthorized');
    
    // Authorization error (403)
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
    $json = $response->json();
    expect($json)->toHaveKey('error');
    expect($json)->toHaveKey('message');
    expect($json['error'])->toBe('Forbidden');
    
    // Validation error (422) - different structure
    $response = $this->postJson('/api/auth/login', []);
    $response->assertStatus(422);
    $json = $response->json();
    expect($json)->toBeArray();
    expect($json)->toHaveKey('email');
    expect($json)->toHaveKey('password');
});

test('descriptive error messages for different failure types', function () {
    // Test that error messages are helpful and specific
    
    // Invalid credentials - need to create user first
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => hash('sha256', 'correct-password' . 'salt'),
        'roles' => ['employee']
    ]);
    
    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => 'wrong-password'
    ]);
    
    expect($response->status())->toBe(401);
    expect($response->json('message'))->toBe('Invalid credentials');
    
    // Missing token
    $response = $this->getJson('/api/auth/profile');
    $message = $response->json('message');
    expect($message)->toBeString();
    expect($message)->toContain('token');
    
    // Insufficient permissions
    $token = auth()->login($user);
    
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token
    ])->getJson('/api/admin/employers');
    
    $message = $response->json('message');
    expect($message)->toContain('permissions');
    expect($message)->toContain('admin');
    
    // Validation errors
    $response = $this->postJson('/api/auth/register', [
        'email' => 'invalid'
    ]);
    $errors = $response->json();
    expect($errors['email'][0])->toContain('email');
});