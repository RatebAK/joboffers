<?php

// ============================================================
// DO NOT DELETE — Comprehensive tests for authentication endpoints.
// Covers: registration validation (all rules), login success/fail,
// profile retrieval, logout, token refresh, and role assignment.
// ============================================================

use App\Models\User;

// ── Registration ──────────────────────────────────────────────

test('register succeeds with valid employee payload', function () {
    $email = 'reg_employee_' . uniqid() . '@test.com';

    $response = $this->postJson('/api/auth/register', [
        'name'                  => 'Test Employee',
        'email'                 => $email,
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]);

    $response->assertStatus(201)
             ->assertJsonStructure(['message', 'user', 'access_token', 'token_type', 'expires_in'])
             ->assertJsonPath('user.roles.0', 'employee');

    User::where('email', $email)->delete();
});

test('register defaults role to employee when no role provided', function () {
    $email = 'reg_default_' . uniqid() . '@test.com';

    $response = $this->postJson('/api/auth/register', [
        'name'                  => 'Default Role',
        'email'                 => $email,
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]);

    $response->assertStatus(201);
    expect($response->json('user.roles'))->toContain('employee');

    User::where('email', $email)->delete();
});

test('register with admin role assigns admin role', function () {
    $email = 'reg_admin_' . uniqid() . '@test.com';

    $response = $this->postJson('/api/auth/register', [
        'name'                  => 'Admin User',
        'email'                 => $email,
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'role'                  => 'admin',
    ]);

    $response->assertStatus(201);
    expect($response->json('user.roles'))->toContain('admin');

    User::where('email', $email)->delete();
});

test('register rejects duplicate email', function () {
    $user = User::factory()->create();

    $this->postJson('/api/auth/register', [
        'name'                  => 'Duplicate',
        'email'                 => $user->email,
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
    ])->assertStatus(422)->assertJsonStructure(['email']);

    $user->delete();
});

test('register rejects missing name', function () {
    $this->postJson('/api/auth/register', [
        'email'                 => 'noname_' . uniqid() . '@test.com',
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
    ])->assertStatus(422)->assertJsonStructure(['name']);
});

test('register rejects name shorter than 2 characters', function () {
    $this->postJson('/api/auth/register', [
        'name'                  => 'A',
        'email'                 => 'short_' . uniqid() . '@test.com',
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
    ])->assertStatus(422)->assertJsonStructure(['name']);
});

test('register rejects password without uppercase letter', function () {
    $this->postJson('/api/auth/register', [
        'name'                  => 'Test User',
        'email'                 => 'pw_' . uniqid() . '@test.com',
        'password'              => 'password1!',
        'password_confirmation' => 'password1!',
    ])->assertStatus(422)->assertJsonStructure(['password']);
});

test('register rejects password without digit', function () {
    $this->postJson('/api/auth/register', [
        'name'                  => 'Test User',
        'email'                 => 'pw_' . uniqid() . '@test.com',
        'password'              => 'Password!',
        'password_confirmation' => 'Password!',
    ])->assertStatus(422)->assertJsonStructure(['password']);
});

test('register rejects password without special character', function () {
    $this->postJson('/api/auth/register', [
        'name'                  => 'Test User',
        'email'                 => 'pw_' . uniqid() . '@test.com',
        'password'              => 'Password1',
        'password_confirmation' => 'Password1',
    ])->assertStatus(422)->assertJsonStructure(['password']);
});

test('register rejects password shorter than 8 characters', function () {
    $this->postJson('/api/auth/register', [
        'name'                  => 'Test User',
        'email'                 => 'pw_' . uniqid() . '@test.com',
        'password'              => 'Pa1!',
        'password_confirmation' => 'Pa1!',
    ])->assertStatus(422)->assertJsonStructure(['password']);
});

test('register rejects mismatched password confirmation', function () {
    $this->postJson('/api/auth/register', [
        'name'                  => 'Test User',
        'email'                 => 'pw_' . uniqid() . '@test.com',
        'password'              => 'Password1!',
        'password_confirmation' => 'Different1!',
    ])->assertStatus(422)->assertJsonStructure(['password']);
});

test('register rejects invalid role value', function () {
    $this->postJson('/api/auth/register', [
        'name'                  => 'Test User',
        'email'                 => 'role_' . uniqid() . '@test.com',
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'role'                  => 'superuser',
    ])->assertStatus(422)->assertJsonStructure(['role']);
});

test('register returns access_token and expires_in', function () {
    $email = 'token_check_' . uniqid() . '@test.com';

    $response = $this->postJson('/api/auth/register', [
        'name'                  => 'Token Check',
        'email'                 => $email,
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
    ]);

    $response->assertStatus(201);
    expect($response->json('access_token'))->not->toBeNull();
    expect($response->json('expires_in'))->toBeGreaterThan(0);
    expect($response->json('token_type'))->toBe('bearer');

    User::where('email', $email)->delete();
});

// ── Login ─────────────────────────────────────────────────────

test('login succeeds with correct credentials', function () {
    $email = 'login_ok_' . uniqid() . '@test.com';
    $this->postJson('/api/auth/register', [
        'name'                  => 'Login User',
        'email'                 => $email,
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
    ])->assertStatus(201);

    $response = $this->postJson('/api/auth/login', [
        'email'    => $email,
        'password' => 'Password1!',
    ]);

    $response->assertStatus(200)
             ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user'])
             ->assertJsonPath('token_type', 'bearer');

    User::where('email', $email)->delete();
});

test('login returns user roles in response', function () {
    $email = 'login_roles_' . uniqid() . '@test.com';
    $this->postJson('/api/auth/register', [
        'name'                  => 'Roles Check',
        'email'                 => $email,
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'role'                  => 'employer',
    ])->assertStatus(201);

    $response = $this->postJson('/api/auth/login', [
        'email'    => $email,
        'password' => 'Password1!',
    ]);

    $response->assertStatus(200);
    expect($response->json('user.roles'))->toContain('employer');

    User::where('email', $email)->delete();
});

test('login fails with wrong password', function () {
    $user = User::factory()->create();

    $this->postJson('/api/auth/login', [
        'email'    => $user->email,
        'password' => 'WrongPassword1!',
    ])->assertStatus(401)->assertJsonStructure(['error', 'message']);

    $user->delete();
});

test('login fails with non-existent email', function () {
    $this->postJson('/api/auth/login', [
        'email'    => 'ghost_' . uniqid() . '@test.com',
        'password' => 'Password1!',
    ])->assertStatus(401);
});

test('login rejects missing email', function () {
    $this->postJson('/api/auth/login', [
        'password' => 'Password1!',
    ])->assertStatus(422)->assertJsonStructure(['email']);
});

test('login rejects missing password', function () {
    $this->postJson('/api/auth/login', [
        'email' => 'test@test.com',
    ])->assertStatus(422)->assertJsonStructure(['password']);
});

// ── Profile ───────────────────────────────────────────────────

test('authenticated user can fetch their profile', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);

    $this->withToken($token)->getJson('/api/auth/profile')
         ->assertStatus(200)
         ->assertJsonPath('email', $user->email);

    $user->delete();
});

test('unauthenticated user cannot fetch profile', function () {
    $this->getJson('/api/auth/profile')->assertStatus(401);
});

test('profile response includes roles array', function () {
    $user  = User::factory()->admin()->create();
    $token = auth('api')->login($user);

    $response = $this->withToken($token)->getJson('/api/auth/profile')->assertStatus(200);
    expect($response->json('roles'))->toContain('admin');

    $user->delete();
});

// ── Logout ────────────────────────────────────────────────────

test('authenticated user can logout', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);

    $this->withToken($token)->postJson('/api/auth/logout')
         ->assertStatus(200)
         ->assertJsonPath('message', 'User successfully signed out');

    $user->delete();
});

test('unauthenticated user cannot logout', function () {
    $this->postJson('/api/auth/logout')->assertStatus(401);
});

// ── Token Refresh ─────────────────────────────────────────────

test('authenticated user can refresh their token', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);

    $response = $this->withToken($token)->postJson('/api/auth/refresh');

    $response->assertStatus(200)
             ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user']);

    expect($response->json('access_token'))->not->toBeNull();

    $user->delete();
});

test('unauthenticated user cannot refresh token', function () {
    $this->postJson('/api/auth/refresh')->assertStatus(401);
});
