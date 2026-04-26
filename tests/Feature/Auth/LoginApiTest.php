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

test('successful login with valid credentials', function () {
    $password = 'Test@123';
    $user = User::create([
        'name' => fake()->name(),
        'email' => 'test@example.com',
        'password' => hash('sha256', $password . 'salt'),
        'roles' => ['employee']
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
    expect($response->json('user.email'))->toBe($user->email);
    expect($response->json('user.roles'))->toBe(['employee']);
});

test('login failure with wrong password', function () {
    $user = User::create([
        'name' => fake()->name(),
        'email' => 'test@example.com',
        'password' => hash('sha256', 'correct-password' . 'salt'),
        'roles' => ['employee']
    ]);

    $response = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password'
    ]);

    $response->assertStatus(401)
        ->assertJson(['error' => 'Unauthorized']);
});

test('login failure with non-existent email', function () {
    $response = $this->postJson('/api/auth/login', [
        'email' => 'nonexistent@example.com',
        'password' => 'any-password'
    ]);

    $response->assertStatus(401)
        ->assertJson(['error' => 'Unauthorized']);
});

test('login response structure', function () {
    $password = 'Test@123';
    $user = User::create([
        'name' => 'John Doe',
        'email' => 'john@example.com',
        'password' => hash('sha256', $password . 'salt'),
        'roles' => ['employer', 'employee']
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

    $responseData = $response->json();
    expect($responseData['user']['name'])->toBe('John Doe');
    expect($responseData['user']['email'])->toBe('john@example.com');
    expect($responseData['user']['roles'])->toBe(['employer', 'employee']);
    expect($responseData['user'])->not->toHaveKey('password');
});

test('login validation errors', function () {
    // Test missing email
    $response = $this->postJson('/api/auth/login', [
        'password' => 'password123'
    ]);

    $response->assertStatus(422);
    expect($response->json())->toHaveKey('email');

    // Test missing password
    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com'
    ]);

    $response->assertStatus(422);
    expect($response->json())->toHaveKey('password');

    // Test invalid email format
    $response = $this->postJson('/api/auth/login', [
        'email' => 'invalid-email',
        'password' => 'password123'
    ]);

    $response->assertStatus(422);
    expect($response->json())->toHaveKey('email');

    // Test short password
    $response = $this->postJson('/api/auth/login', [
        'email' => 'test@example.com',
        'password' => '123'
    ]);

    $response->assertStatus(422);
    expect($response->json())->toHaveKey('password');
});