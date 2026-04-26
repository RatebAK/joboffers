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

// Test registration with each specific role (admin, employer, employee)
test('user can register with admin role', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Admin User',
        'email' => 'admin@gmail.com',
        'password' => 'Test@123',
        'password_confirmation' => 'Test@123',
        'role' => 'admin'
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'user' => ['name', 'email', 'roles'],
            'access_token',
            'token_type',
            'expires_in'
        ]);

    expect($response->json('user.roles'))->toContain('admin');
    
    $user = User::where('email', 'admin@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->roles)->toContain('admin');
});

test('user can register with employer role', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Employer User',
        'email' => 'employer@gmail.com',
        'password' => 'Test@123',
        'password_confirmation' => 'Test@123',
        'role' => 'employer'
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'user' => ['name', 'email', 'roles'],
            'access_token',
            'token_type',
            'expires_in'
        ]);

    expect($response->json('user.roles'))->toContain('employer');
    
    $user = User::where('email', 'employer@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->roles)->toContain('employer');
});

test('user can register with employee role', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Employee User',
        'email' => 'employee@gmail.com',
        'password' => 'Test@123',
        'password_confirmation' => 'Test@123',
        'role' => 'employee'
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'user' => ['name', 'email', 'roles'],
            'access_token',
            'token_type',
            'expires_in'
        ]);

    expect($response->json('user.roles'))->toContain('employee');
    
    $user = User::where('email', 'employee@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->roles)->toContain('employee');
});

// Test registration with missing role field (should default to employee)
test('user registration without role defaults to employee', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Default User',
        'email' => 'default@gmail.com',
        'password' => 'Test@123',
        'password_confirmation' => 'Test@123'
    ]);

    $response->assertStatus(201);
    
    expect($response->json('user.roles'))->toContain('employee');
    
    $user = User::where('email', 'default@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->roles)->toContain('employee');
});

// Test registration with duplicate email
test('registration with duplicate email is rejected', function () {
    // Create first user
    User::create([
        'name' => 'First User',
        'email' => 'duplicate@gmail.com',
        'password' => hash('sha256', 'Test@123' . 'salt'), // Use same fallback as controller
        'roles' => ['employee']
    ]);

    // Try to register with same email
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Second User',
        'email' => 'duplicate@gmail.com',
        'password' => 'Test@456',
        'password_confirmation' => 'Test@456',
        'role' => 'employee'
    ]);

    $response->assertStatus(422);
    expect($response->json())->toHaveKey('email');
});

// Test password confirmation mismatch
test('registration with password confirmation mismatch is rejected', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Test User',
        'email' => 'test@gmail.com',
        'password' => 'Test@123',
        'password_confirmation' => 'Test@456',
        'role' => 'employee'
    ]);

    $response->assertStatus(422);
    expect($response->json())->toHaveKey('password');
});

// Test weak password rejection
test('registration with weak password is rejected', function () {
    $weakPasswords = [
        'short',           // Too short
        'nouppercase1!',   // No uppercase
        'NOLOWERCASE1!',   // No lowercase
        'NoNumbers!',      // No numbers
        'NoSpecial123',    // No special characters
    ];

    foreach ($weakPasswords as $index => $password) {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test' . $index . '@gmail.com',
            'password' => $password,
            'password_confirmation' => $password,
            'role' => 'employee'
        ]);

        $response->assertStatus(422);
        expect($response->json())->toHaveKey('password');
    }
});

// Test invalid role rejection
test('registration with invalid role is rejected', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Test User',
        'email' => 'test@gmail.com',
        'password' => 'Test@123',
        'password_confirmation' => 'Test@123',
        'role' => 'invalid_role'
    ]);

    $response->assertStatus(422);
    expect($response->json())->toHaveKey('role');
});

// Test invalid email format rejection
test('registration with invalid email format is rejected', function () {
    $invalidEmails = [
        'notanemail',
        '@nodomain.com',
        'spaces in@gmail.com',
    ];

    foreach ($invalidEmails as $email) {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => $email,
            'password' => 'Test@123',
            'password_confirmation' => 'Test@123',
            'role' => 'employee'
        ]);

        $response->assertStatus(422);
        expect($response->json())->toHaveKey('email');
    }
});

// Test that password is hashed
test('password is hashed before storing', function () {
    $plainPassword = 'Test@123';
    
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Test User',
        'email' => 'test@gmail.com',
        'password' => $plainPassword,
        'password_confirmation' => $plainPassword,
        'role' => 'employee'
    ]);

    $response->assertStatus(201);
    
    $user = User::where('email', 'test@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->password)->not->toBe($plainPassword);
    
    // Check if password was hashed (either bcrypt or fallback)
    $isValidHash = password_verify($plainPassword, $user->password) || 
                   $user->password === hash('sha256', $plainPassword . 'salt');
    expect($isValidHash)->toBeTrue();
});

// Test that roles is stored as an array
test('roles field is stored as an array', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Test User',
        'email' => 'test@gmail.com',
        'password' => 'Test@123',
        'password_confirmation' => 'Test@123',
        'role' => 'employee'
    ]);

    $response->assertStatus(201);
    
    $user = User::where('email', 'test@gmail.com')->first();
    expect($user)->not->toBeNull();
    expect($user->roles)->toBeArray();
    expect(count($user->roles))->toBe(1);
});
