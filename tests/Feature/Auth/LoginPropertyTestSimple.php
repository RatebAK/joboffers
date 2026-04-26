<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Property 8: Valid credentials return token
 * **Validates: Requirements 2.1**
 */
test('Property 8: valid credentials return token', function () {
    User::truncate();
    
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => Hash::make('Test@123'),
        'roles' => ['employee']
    ]);
    
    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => 'Test@123'
    ]);
    
    expect($response->status())->toBe(200);
    expect($response->json())->toHaveKeys(['access_token', 'token_type', 'expires_in', 'user']);
    expect($response->json('access_token'))->not->toBeEmpty();
    expect($response->json('token_type'))->toBe('bearer');
    expect($response->json('user.email'))->toBe('test@example.com');
    expect($response->json('user.roles'))->toContain('employee');
});

/**
 * Property 9: Invalid credentials are rejected
 * **Validates: Requirements 2.2**
 */
test('Property 9: invalid credentials are rejected', function () {
    User::truncate();
    
    User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => Hash::make('Test@123'),
        'roles' => ['employee']
    ]);
    
    // Test with wrong password
    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => 'WrongPassword'
    ]);
    
    expect($response->status())->toBe(401);
    expect($response->json('error'))->toBe('Unauthorized');
    
    // Test with non-existent email
    $response = $this->postJson('/api/auth/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'Test@123'
    ]);
    
    expect($response->status())->toBe(401);
    expect($response->json('error'))->toBe('Unauthorized');
});

/**
 * Property 10: Token contains user ID in subject claim
 * **Validates: Requirements 2.4**
 */
test('Property 10: token contains user ID in subject claim', function () {
    User::truncate();
    
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => Hash::make('Test@123'),
        'roles' => ['employee']
    ]);
    
    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => 'Test@123'
    ]);
    
    expect($response->status())->toBe(200);
    $token = $response->json('access_token');
    
    // Decode JWT token to verify subject claim
    $tokenParts = explode('.', $token);
    expect(count($tokenParts))->toBe(3);
    
    $payload = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', $tokenParts[1]))), true);
    
    expect($payload)->toHaveKey('sub');
    expect($payload['sub'])->toBe((string)$user->_id);
});

/**
 * Property 11: Login response format
 * **Validates: Requirements 2.5**
 */
test('Property 11: login response format', function () {
    User::truncate();
    
    User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => Hash::make('Test@123'),
        'roles' => ['admin']
    ]);
    
    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => 'Test@123'
    ]);
    
    expect($response->status())->toBe(200);
    expect($response->json())->toHaveKeys(['access_token', 'token_type', 'expires_in', 'user']);
    expect($response->json('token_type'))->toBe('bearer');
    expect($response->json('expires_in'))->toBeInt();
    expect($response->json('expires_in'))->toBeGreaterThan(0);
    
    $userObject = $response->json('user');
    expect($userObject)->toHaveKeys(['_id', 'name', 'email', 'roles']);
    expect($userObject['email'])->toBe('test@example.com');
    expect($userObject['roles'])->toBeArray();
    expect($userObject['roles'])->toContain('admin');
    expect($userObject)->not->toHaveKey('password');
});

/**
 * Property 12: Token expiration matches configuration
 * **Validates: Requirements 2.6**
 */
test('Property 12: token expiration matches configuration', function () {
    User::truncate();
    
    User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => Hash::make('Test@123'),
        'roles' => ['employee']
    ]);
    
    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => 'Test@123'
    ]);
    
    expect($response->status())->toBe(200);
    $expiresIn = $response->json('expires_in');
    
    $expectedTTL = config('jwt.ttl', 60) * 60;
    expect($expiresIn)->toBe($expectedTTL);
    
    // Verify token expiration
    $token = $response->json('access_token');
    $tokenParts = explode('.', $token);
    $payload = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', $tokenParts[1]))), true);
    
    expect($payload)->toHaveKey('exp');
    expect($payload)->toHaveKey('iat');
    
    $actualTTL = $payload['exp'] - $payload['iat'];
    expect($actualTTL)->toBe($expectedTTL);
});