<?php

// =============================================================================
// AuthLoginTest — POST /api/auth/login
// =============================================================================

use App\Models\User;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;

/** A user with a known password that POST /api/auth/login can verify. */
function loginUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'password' => testPasswordHash('Test@123'),
    ], $attributes));
}

test('logging in with valid credentials returns a bearer token', function () {
    $user = loginUser(['roles' => ['employee']]);

    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Test@123'])
        ->assertOk()
        ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user'])
        ->assertJsonPath('token_type', 'bearer')
        ->assertJsonPath('user.email', $user->email);
});

test('the login response echoes the users roles and omits the password', function () {
    $user = loginUser(['roles' => ['employer', 'employee']]);

    $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Test@123'])->assertOk();

    expect($response->json('user.roles'))->toBe(['employer', 'employee'])
        ->and($response->json('user'))->not->toHaveKey('password');
});

test('logging in with a wrong password returns 401', function () {
    $user = loginUser();

    $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'WrongPassword@1'])
        ->assertUnauthorized()
        ->assertJson(['error' => 'Unauthorized']);
});

test('logging in with a non-existent email returns 401', function () {
    $this->postJson('/api/auth/login', ['email' => 'ghost@example.com', 'password' => 'Test@123'])
        ->assertUnauthorized()
        ->assertJson(['error' => 'Unauthorized']);
});

test('login validation rejects missing or malformed fields', function (array $payload, string $field) {
    $this->postJson('/api/auth/login', $payload)
        ->assertStatus(422)
        ->assertJsonStructure([$field]);
})->with([
    'missing email'    => [['password' => 'Test@123'], 'email'],
    'missing password' => [['email' => 'user@example.com'], 'password'],
    'invalid email'    => [['email' => 'not-an-email', 'password' => 'Test@123'], 'email'],
    'short password'   => [['email' => 'user@example.com', 'password' => '12'], 'password'],
]);

test('the issued token carries the user id in its subject claim', function () {
    $user = loginUser();

    $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Test@123'])
        ->assertOk()
        ->json('access_token');

    expect(JWTAuth::setToken($token)->getPayload()->get('sub'))->toBe((string) $user->_id);
});

test('the token expiry matches the configured JWT ttl', function () {
    $user = loginUser();

    $expiresIn = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Test@123'])
        ->assertOk()
        ->json('expires_in');

    expect($expiresIn)->toBe(config('jwt.ttl') * 60);
});
