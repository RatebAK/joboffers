<?php

// Covers the Google OAuth endpoints: connect/status/disconnect (with the
// GoogleMeetService faked) plus auth guards and the public callback redirect.

use App\Services\GoogleMeetService;

beforeEach(function () {
    [$this->user, $this->token] = userWithToken('employer');
});

test('connect returns auth_url', function () {
    $mockService = Mockery::mock(GoogleMeetService::class);
    $mockService->shouldReceive('getAuthUrl')
        ->once()
        ->andReturn('https://accounts.google.com/o/oauth2/v2/auth?client_id=test');

    $this->app->instance(GoogleMeetService::class, $mockService);

    $response = $this->withToken($this->token)->getJson('/api/google/connect')
        ->assertOk()
        ->assertJsonStructure(['auth_url']);

    expect($response->json('auth_url'))->toContain('https://accounts.google.com');
});

test('status returns connected false for new user', function () {
    $mockService = Mockery::mock(GoogleMeetService::class);
    $mockService->shouldReceive('isConnected')->once()->andReturn(false);

    $this->app->instance(GoogleMeetService::class, $mockService);

    $this->withToken($this->token)->getJson('/api/google/status')
        ->assertOk()
        ->assertJsonPath('connected', false);
});

test('disconnect succeeds', function () {
    $mockService = Mockery::mock(GoogleMeetService::class);
    $mockService->shouldReceive('disconnect')->once();

    $this->app->instance(GoogleMeetService::class, $mockService);

    $this->withToken($this->token)->deleteJson('/api/google/disconnect')
        ->assertOk()
        ->assertJsonStructure(['message']);
});

test('unauthenticated request returns 401 on connect', function () {
    $this->getJson('/api/google/connect')->assertUnauthorized();
});

test('unauthenticated request returns 401 on status', function () {
    $this->getJson('/api/google/status')->assertUnauthorized();
});

test('unauthenticated request returns 401 on disconnect', function () {
    $this->deleteJson('/api/google/disconnect')->assertUnauthorized();
});

test('callback with error param redirects to frontend with denied', function () {
    $response = $this->getJson('/api/google/callback?error=access_denied');

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toContain('google=denied');
});
