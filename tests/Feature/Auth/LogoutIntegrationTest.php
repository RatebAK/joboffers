<?php

use App\Models\User;

/**
 * Integration tests to verify all logout requirements are met
 * Requirements: 4.1, 4.2, 4.3
 */

test('complete logout flow meets all requirements', function () {
    // Create a user
    $user = User::factory()->create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'roles' => ['employee']
    ]);
    
    // Step 1: Login to get a token
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password'
    ]);
    
    expect($loginResponse->status())->toBe(200);
    $token = $loginResponse->json('access_token');
    expect($token)->not()->toBeNull();
    
    // Step 2: Verify token works before logout
    $profileResponse = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->getJson('/api/auth/profile');
    
    expect($profileResponse->status())->toBe(200);
    expect($profileResponse->json('email'))->toBe($user->email);
    
    // Step 3: Logout (Requirement 4.1 - token should be blacklisted)
    $logoutResponse = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->postJson('/api/auth/logout');
    
    // Requirement 4.3 - logout returns success message
    expect($logoutResponse->status())->toBe(200);
    expect($logoutResponse->json('message'))->toBe('User successfully signed out');
    
    // Step 4: Verify token is blacklisted (Requirement 4.2)
    $profileAfterLogout = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->getJson('/api/auth/profile');
    
    expect($profileAfterLogout->status())->toBe(401);
    
    // Step 5: Verify blacklisted token fails on other endpoints too
    $refreshAfterLogout = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token,
    ])->postJson('/api/auth/refresh');
    
    expect($refreshAfterLogout->status())->toBe(401);
    
    // Step 6: Verify user can login again with new token
    $newLoginResponse = $this->postJson('/api/auth/login', [
        'email' => $user->email,
        'password' => 'password'
    ]);
    
    expect($newLoginResponse->status())->toBe(200);
    $newToken = $newLoginResponse->json('access_token');
    expect($newToken)->not()->toBeNull();
    expect($newToken)->not()->toBe($token); // Should be different token
    
    // Step 7: Verify new token works
    $profileWithNewToken = $this->withHeaders([
        'Authorization' => 'Bearer ' . $newToken,
    ])->getJson('/api/auth/profile');
    
    expect($profileWithNewToken->status())->toBe(200);
    expect($profileWithNewToken->json('email'))->toBe($user->email);
});