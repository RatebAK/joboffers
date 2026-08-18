<?php

use App\Models\GoogleOAuthToken;
use App\Models\User;
use App\Services\GoogleMeetService;

beforeEach(function () {
    $this->user = User::factory()->employer()->create();
    $this->token = auth('api')->login($this->user);
});

afterEach(function () {
    GoogleOAuthToken::where('user_id', (string) $this->user->_id)->delete();
    $this->user->delete();
});

test('connect returns auth_url', function () {
    $mockService = Mockery::mock(GoogleMeetService::class);
    $mockService->shouldReceive('getAuthUrl')
        ->once()
        ->andReturn('https://accounts.google.com/o/oauth2/v2/auth?client_id=test');

    $this->app->instance(GoogleMeetService::class, $mockService);

    $response = $this->withToken($this->token)
        ->getJson('/api/google/connect');

    $response->assertStatus(200)
        ->assertJsonStructure(['auth_url']);

    expect($response->json('auth_url'))->toContain('https://accounts.google.com');
});

test('status returns connected false for new user', function () {
    $mockService = Mockery::mock(GoogleMeetService::class);
    $mockService->shouldReceive('isConnected')
        ->once()
        ->andReturn(false);

    $this->app->instance(GoogleMeetService::class, $mockService);

    $response = $this->withToken($this->token)
        ->getJson('/api/google/status');

    $response->assertStatus(200)
        ->assertJsonPath('connected', false);
});

test('disconnect succeeds', function () {
    $mockService = Mockery::mock(GoogleMeetService::class);
    $mockService->shouldReceive('disconnect')
        ->once();

    $this->app->instance(GoogleMeetService::class, $mockService);

    $response = $this->withToken($this->token)
        ->deleteJson('/api/google/disconnect');

    $response->assertStatus(200)
        ->assertJsonStructure(['message']);
});

test('unauthenticated request returns 401 on connect', function () {
    $response = $this->getJson('/api/google/connect');
    $response->assertStatus(401);
});

test('unauthenticated request returns 401 on status', function () {
    $response = $this->getJson('/api/google/status');
    $response->assertStatus(401);
});

test('unauthenticated request returns 401 on disconnect', function () {
    $response = $this->deleteJson('/api/google/disconnect');
    $response->assertStatus(401);
});

test('callback with error param redirects to frontend with denied', function () {
    $response = $this->getJson('/api/google/callback?error=access_denied');

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toContain('google=denied');
});
