<?php

// =============================================================================
// ErrorHandlingTest — the shape and messages of auth error responses.
//
// This API returns validation errors at the top level ({"field": [...]}) and
// auth/authorization errors as {"error": ..., "message": ...}.
// =============================================================================

use App\Models\User;

test('invalid login credentials return 401 with an Invalid credentials message', function () {
    User::factory()->create(['email' => 'known@example.com', 'password' => testPasswordHash('Right@123')]);

    $this->postJson('/api/auth/login', ['email' => 'known@example.com', 'password' => 'Wrong@123'])
        ->assertUnauthorized()
        ->assertJson(['error' => 'Unauthorized', 'message' => 'Invalid credentials']);
});

test('a non-existent email returns the same Invalid credentials message', function () {
    $this->postJson('/api/auth/login', ['email' => 'ghost@example.com', 'password' => 'Any@123456'])
        ->assertUnauthorized()
        ->assertJson(['error' => 'Unauthorized', 'message' => 'Invalid credentials']);
});

test('a missing token returns 401 with a message mentioning the token', function () {
    $response = $this->getJson('/api/auth/profile')->assertUnauthorized();

    expect($response->json('message'))->toContain('token');
});

test('insufficient permissions return 403 naming the required role', function () {
    $this->withToken(tokenFor('employee'))
        ->getJson('/api/admin/employers')
        ->assertForbidden()
        ->assertJson([
            'error'   => 'Forbidden',
            'message' => 'Insufficient permissions. Required roles: admin',
        ]);
});

test('registration validation returns 422 with a field-keyed body', function () {
    $errors = $this->postJson('/api/auth/register', [
        'name'     => '',
        'email'    => 'invalid-email',
        'password' => '123',
        'role'     => 'invalid-role',
    ])->assertStatus(422)->json();

    expect($errors)->toHaveKeys(['name', 'email', 'password', 'role'])
        ->and($errors['email'][0])->toContain('email');
});

test('all auth error responses are JSON', function () {
    $this->getJson('/api/auth/profile')
        ->assertUnauthorized()
        ->assertHeader('content-type', 'application/json');

    $this->postJson('/api/auth/login', [])
        ->assertStatus(422)
        ->assertHeader('content-type', 'application/json');
});
