<?php

// =============================================================================
// AuthRegistrationTest — POST /api/auth/register
// =============================================================================

use App\Models\User;

$validPayload = fn (array $overrides = []) => array_merge([
    'name'                  => 'Test User',
    'email'                 => 'user@example.com',
    'password'              => 'Test@123',
    'password_confirmation' => 'Test@123',
], $overrides);

test('a user can register with each valid role', function (string $role) {
    $this->postJson('/api/auth/register', [
        'name'                  => 'Role User',
        'email'                 => "{$role}@example.com",
        'password'              => 'Test@123',
        'password_confirmation' => 'Test@123',
        'role'                  => $role,
    ])
        ->assertCreated()
        ->assertJsonStructure(['message', 'user' => ['name', 'email', 'roles'], 'access_token', 'token_type', 'expires_in'])
        ->assertJsonPath('user.roles', [$role]);
})->with(['admin', 'employer', 'employee']);

test('registration without a role defaults to employee', function () use ($validPayload) {
    $this->postJson('/api/auth/register', $validPayload())
        ->assertCreated()
        ->assertJsonPath('user.roles', ['employee']);
});

test('registration is rejected for a duplicate email', function () use ($validPayload) {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->postJson('/api/auth/register', $validPayload(['email' => 'taken@example.com']))
        ->assertStatus(422)
        ->assertJsonStructure(['email']);
});

test('registration requires the password confirmation to match', function () use ($validPayload) {
    $this->postJson('/api/auth/register', $validPayload(['password_confirmation' => 'Different@123']))
        ->assertStatus(422)
        ->assertJsonStructure(['password']);
});

test('registration rejects weak passwords', function (string $password) use ($validPayload) {
    $this->postJson('/api/auth/register', $validPayload(['password' => $password, 'password_confirmation' => $password]))
        ->assertStatus(422)
        ->assertJsonStructure(['password']);
})->with([
    'too short'        => 'Ab1!',
    'no uppercase'     => 'nouppercase1!',
    'no lowercase'     => 'NOLOWERCASE1!',
    'no digit'         => 'NoNumbers!',
    'no special char'  => 'NoSpecial123',
]);

test('registration rejects an invalid role', function () use ($validPayload) {
    $this->postJson('/api/auth/register', $validPayload(['role' => 'superuser']))
        ->assertStatus(422)
        ->assertJsonStructure(['role']);
});

test('registration rejects an invalid email format', function (string $email) use ($validPayload) {
    $this->postJson('/api/auth/register', $validPayload(['email' => $email]))
        ->assertStatus(422)
        ->assertJsonStructure(['email']);
})->with([
    'no domain'   => 'notanemail',
    'no local'    => '@nodomain.com',
    'has spaces'  => 'spaces in@example.com',
]);

test('the password is hashed before being stored', function () use ($validPayload) {
    $this->postJson('/api/auth/register', $validPayload(['email' => 'hash@example.com']))->assertCreated();

    $user = User::where('email', 'hash@example.com')->first();
    expect($user->password)->not->toBe('Test@123')
        ->and(password_verify('Test@123', $user->password) || $user->password === hash('sha256', 'Test@123'.'salt'))
        ->toBeTrue();
});

test('the roles field is stored as an array', function () use ($validPayload) {
    $this->postJson('/api/auth/register', $validPayload(['email' => 'array@example.com']))->assertCreated();

    expect(User::where('email', 'array@example.com')->first()->roles)->toBeArray();
});

test('a successful registration returns a bearer token and user', function () use ($validPayload) {
    $response = $this->postJson('/api/auth/register', $validPayload(['email' => 'token@example.com']))->assertCreated();

    expect($response->json('token_type'))->toBe('bearer')
        ->and($response->json('expires_in'))->toBeGreaterThan(0)
        ->and($response->json('access_token'))->not->toBeEmpty();
});
