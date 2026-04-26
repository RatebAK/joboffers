<?php

use App\Models\User;

test('logout returns success message', function () {
    // Create a user
    $user = User::factory()->create([
        'roles' => ['employee']
    ]);
    
    // Login to get a token
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password'
    ]);
    
    $token = $loginResponse->json('access_token');
    
    // Test logout
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->postJson('/api/auth/logout');
    
    $response->assertStatus(200)
             ->assertJson([
                 'message' => 'User successfully signed out'
             ]);
});

test('token is blacklisted after logout', function () {
    // Create a user
    $user = User::factory()->create([
        'roles' => ['employee']
    ]);
    
    // Login to get a token
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password'
    ]);
    
    $token = $loginResponse->json('access_token');
    
    // Verify token works before logout
    $profileResponse = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->getJson('/api/auth/profile');
    
    $profileResponse->assertStatus(200);
    
    // Logout
    $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->postJson('/api/auth/logout');
    
    // Try to use the token after logout - should fail
    $profileAfterLogout = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->getJson('/api/auth/profile');
    
    $profileAfterLogout->assertStatus(401);
});

test('logout without token returns unauthorized', function () {
    $response = $this->postJson('/api/auth/logout');
    
    $response->assertStatus(401);
});

test('successful logout with admin user', function () {
    $user = User::factory()->create([
        'roles' => ['admin']
    ]);
    
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password'
    ]);
    
    $token = $loginResponse->json('access_token');
    
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->postJson('/api/auth/logout');
    
    $response->assertStatus(200)
             ->assertJson([
                 'message' => 'User successfully signed out'
             ]);
});

test('successful logout with employer user', function () {
    $user = User::factory()->create([
        'roles' => ['employer']
    ]);
    
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password'
    ]);
    
    $token = $loginResponse->json('access_token');
    
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->postJson('/api/auth/logout');
    
    $response->assertStatus(200)
             ->assertJson([
                 'message' => 'User successfully signed out'
             ]);
});

test('successful logout with multi-role user', function () {
    $user = User::factory()->create([
        'roles' => ['admin', 'employer', 'employee']
    ]);
    
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password'
    ]);
    
    $token = $loginResponse->json('access_token');
    
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->postJson('/api/auth/logout');
    
    $response->assertStatus(200)
             ->assertJson([
                 'message' => 'User successfully signed out'
             ]);
});

test('logout response is JSON format', function () {
    $user = User::factory()->create([
        'roles' => ['employee']
    ]);
    
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password'
    ]);
    
    $token = $loginResponse->json('access_token');
    
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->postJson('/api/auth/logout');
    
    $response->assertStatus(200)
             ->assertHeader('content-type', 'application/json')
             ->assertJsonStructure([
                 'message'
             ]);
});

test('using token after logout fails on multiple endpoints', function () {
    $user = User::factory()->create([
        'roles' => ['employee']
    ]);
    
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password'
    ]);
    
    $token = $loginResponse->json('access_token');
    
    // Logout
    $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->postJson('/api/auth/logout');
    
    // Test multiple endpoints with blacklisted token
    $endpoints = [
        ['method' => 'get', 'url' => '/api/auth/profile'],
        ['method' => 'post', 'url' => '/api/auth/refresh'],
        ['method' => 'post', 'url' => '/api/auth/logout'], // Should still fail even for logout
    ];
    
    foreach ($endpoints as $endpoint) {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->{$endpoint['method'] . 'Json'}($endpoint['url']);
        
        $response->assertStatus(401);
    }
});