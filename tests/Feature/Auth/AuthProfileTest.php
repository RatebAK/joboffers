<?php

// =============================================================================
// AuthProfileTest — GET /api/auth/profile
// =============================================================================

test('an authenticated user can retrieve their profile', function () {
    [$user, $token] = userWithToken('employee');

    $this->withToken($token)->getJson('/api/auth/profile')
        ->assertOk()
        ->assertJsonStructure(['id', 'name', 'email', 'roles'])
        ->assertJsonPath('email', $user->email);
});

test('the profile echoes the users roles for any role', function (string $role) {
    [, $token] = userWithToken($role);

    $this->withToken($token)->getJson('/api/auth/profile')
        ->assertOk()
        ->assertJsonPath('roles', [$role]);
})->with(['admin', 'employer', 'employee']);

test('the profile never includes the password or remember token', function () {
    [, $token] = userWithToken('employee');

    $data = $this->withToken($token)->getJson('/api/auth/profile')->assertOk()->json();

    expect($data)->not->toHaveKey('password')
        ->and($data)->not->toHaveKey('remember_token');
});

test('an unauthenticated profile request returns 401', function () {
    $this->getJson('/api/auth/profile')->assertUnauthorized();
});
