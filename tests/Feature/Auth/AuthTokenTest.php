<?php

// =============================================================================
// AuthTokenTest — token lifecycle: refresh, logout/blacklist, and validation
// on the protected /api/auth/* endpoints.
// =============================================================================

use App\Models\User;

/** Login through the API and return [user, token]. */
function authedUser(array $attributes = []): array
{
    $user  = User::factory()->create(array_merge(['password' => testPasswordHash('Test@123')], $attributes));
    $token = test()->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Test@123'])
        ->json('access_token');

    return [$user, $token];
}

// ── Refresh ──────────────────────────────────────────────────────────────

test('refreshing issues a new, different token', function () {
    [, $token] = authedUser();

    $new = $this->withToken($token)->postJson('/api/auth/refresh')
        ->assertOk()
        ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user'])
        ->json('access_token');

    expect($new)->not->toBe($token);
});

test('refreshing preserves the user identity', function () {
    [$user, $token] = authedUser(['roles' => ['employer', 'employee']]);

    $this->withToken($token)->postJson('/api/auth/refresh')
        ->assertOk()
        ->assertJsonPath('user.email', $user->email)
        ->assertJsonPath('user.roles', ['employer', 'employee']);
});

test('the old token is blacklisted after a refresh', function () {
    [, $token] = authedUser();

    $this->withToken($token)->postJson('/api/auth/refresh')->assertOk();

    $this->withToken($token)->getJson('/api/auth/profile')->assertUnauthorized();
});

test('refreshing with an invalid or missing token fails', function () {
    $this->withToken('invalid-token')->postJson('/api/auth/refresh')->assertUnauthorized();
    $this->postJson('/api/auth/refresh')->assertUnauthorized();
});

// ── Logout / blacklist ─────────────────────────────────────────────────────

test('logging out returns a success message', function () {
    [, $token] = authedUser();

    $this->withToken($token)->postJson('/api/auth/logout')
        ->assertOk()
        ->assertJsonPath('message', 'User successfully signed out');
});

test('a token is blacklisted after logout', function () {
    [, $token] = authedUser();

    $this->withToken($token)->getJson('/api/auth/profile')->assertOk();
    $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

    $this->withToken($token)->getJson('/api/auth/profile')->assertUnauthorized();
});

test('logging out without a token returns 401', function () {
    $this->postJson('/api/auth/logout')->assertUnauthorized();
});

test('a blacklisted token is rejected across protected endpoints', function () {
    [, $token] = authedUser();
    $this->withToken($token)->postJson('/api/auth/logout')->assertOk();

    $this->withToken($token)->getJson('/api/auth/profile')->assertUnauthorized();
    $this->withToken($token)->postJson('/api/auth/refresh')->assertUnauthorized();
});

// ── Token validation ───────────────────────────────────────────────────────

test('a valid token authenticates a protected request', function () {
    [, $token] = authedUser();

    $this->withToken($token)->getJson('/api/auth/profile')
        ->assertOk()
        ->assertJsonStructure(['id', 'name', 'email', 'roles']);
});

test('a request without an authorization header returns 401', function () {
    $this->getJson('/api/auth/profile')
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
});

test('a malformed token is rejected with 401', function (string $malformed) {
    $this->withToken($malformed)->getJson('/api/auth/profile')
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
})->with([
    'garbage'         => 'invalid-token-format',
    'partial jwt'     => 'eyJ0eXAiOiJKV1QiLCJhbGc',
    'dotted'          => 'not.a.jwt.token',
    'numeric'         => '12345',
    'bad signature'   => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.invalid.signature',
]);

test('an authorization header with the wrong scheme is rejected', function () {
    [, $token] = authedUser();

    $this->withHeader('Authorization', "Basic {$token}")->getJson('/api/auth/profile')->assertUnauthorized();
    $this->withHeader('Authorization', "Token {$token}")->getJson('/api/auth/profile')->assertUnauthorized();
    $this->withHeader('Authorization', $token)->getJson('/api/auth/profile')->assertUnauthorized();
});
