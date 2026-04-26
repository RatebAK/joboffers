<?php

use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

beforeEach(function () {
    // Clear users before each test
    User::truncate();
});

afterEach(function () {
    // Clean up after each test
    User::truncate();
});

// Property 8: Valid credentials return token - Test login with random valid users
test('valid credentials return token', function () {
    for ($i = 0; $i < 20; $i++) {
        // Clear users for each iteration
        User::truncate();
        
        $password = 'Test@123';
        $email = 'valid' . $i . '@gmail.com';
        
        $user = User::create([
            'name' => fake()->name(),
            'email' => $email,
            'password' => hash('sha256', $password . 'salt'), // Use same fallback as controller
            'roles' => [fake()->randomElement(['admin', 'employer', 'employee'])]
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
                'user'
            ]);

        expect($response->json('token_type'))->toBe('bearer');
        expect($response->json('access_token'))->toBeString();
        expect($response->json('expires_in'))->toBeInt();
        expect($response->json('user.email'))->toBe($user->email);
    }
})->group('property-tests');

// Property 9: Invalid credentials are rejected - Test with random invalid credentials
test('invalid credentials are rejected', function () {
    for ($i = 0; $i < 20; $i++) {
        // Clear users for each iteration
        User::truncate();
        
        $email = 'invalid' . $i . '@gmail.com';
        $user = User::create([
            'name' => fake()->name(),
            'email' => $email,
            'password' => hash('sha256', 'correct-password' . 'salt'),
            'roles' => ['employee']
        ]);

        // Test with wrong password
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password'
        ]);

        $response->assertStatus(401)
            ->assertJson(['error' => 'Unauthorized']);

        // Test with non-existent email
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nonexistent' . $i . '@gmail.com',
            'password' => 'any-password'
        ]);

        $response->assertStatus(401)
            ->assertJson(['error' => 'Unauthorized']);
    }
})->group('property-tests');

// Property 10: Token contains user ID in subject claim - Decode and verify token structure
test('token contains user ID in subject claim', function () {
    for ($i = 0; $i < 20; $i++) {
        // Clear users for each iteration
        User::truncate();
        
        $password = 'Test@123';
        $email = 'token' . $i . '@gmail.com';
        
        $user = User::create([
            'name' => fake()->name(),
            'email' => $email,
            'password' => hash('sha256', $password . 'salt'),
            'roles' => ['employee']
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password
        ]);

        $token = $response->json('access_token');
        $payload = JWTAuth::setToken($token)->getPayload();

        expect($payload->get('sub'))->toBe((string) $user->_id);
    }
})->group('property-tests');

// Property 11: Login response format - Verify response contains required fields
test('login response format', function () {
    for ($i = 0; $i < 20; $i++) {
        // Clear users for each iteration
        User::truncate();
        
        $password = 'Test@123';
        $email = 'format' . $i . '@gmail.com';
        
        $user = User::create([
            'name' => fake()->name(),
            'email' => $email,
            'password' => hash('sha256', $password . 'salt'),
            'roles' => [fake()->randomElement(['admin', 'employer', 'employee'])]
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
                'user' => [
                    'name',
                    'email',
                    'roles'
                ]
            ]);

        expect($response->json('user.roles'))->toBeArray();
        expect($response->json('user'))->not->toHaveKey('password');
    }
})->group('property-tests');

// Property 12: Token expiration matches configuration - Verify TTL is respected
test('token expiration matches configuration', function () {
    for ($i = 0; $i < 20; $i++) {
        // Clear users for each iteration
        User::truncate();
        
        $password = 'Test@123';
        $email = 'ttl' . $i . '@gmail.com';
        
        $user = User::create([
            'name' => fake()->name(),
            'email' => $email,
            'password' => hash('sha256', $password . 'salt'),
            'roles' => ['employee']
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => $password
        ]);

        $expiresIn = $response->json('expires_in');
        $expectedTTL = config('jwt.ttl') * 60; // TTL is in minutes, expires_in is in seconds

        expect($expiresIn)->toBe($expectedTTL);
    }
})->group('property-tests');