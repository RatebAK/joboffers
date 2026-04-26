<?php

use App\Models\User;

beforeEach(function () {
    // Clear users before each test
    User::truncate();
});

afterEach(function () {
    // Clean up after each test
    User::truncate();
});

// **Property 26: Valid token authenticates requests** - Test with random valid tokens
test('Property 26: Valid token authenticates requests', function () {
    // **Validates: Requirements 10.1**
    
    for ($i = 0; $i < 20; $i++) {
        // Create random test user
        $user = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => hash('sha256', 'Test@123' . 'salt'),
            'roles' => [fake()->randomElement(['admin', 'employer', 'employee'])]
        ]);
        
        // Generate valid token
        $token = auth()->login($user);
        // Don't logout here - keep the token valid
        
        // Property: Valid token should authenticate request successfully
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token
        ])->getJson('/api/auth/profile');
        
        expect($response->status())->toBe(200);
        expect($response->json())->toHaveKeys(['id', 'name', 'email', 'roles']);
        
        // Clean up - logout to invalidate token, then delete user
        auth()->logout();
        $user->delete();
    }
})->group('property-based-test');

// **Property 27: Malformed tokens are rejected** - Test with random invalid token strings
test('Property 27: Malformed tokens are rejected', function () {
    // **Validates: Requirements 10.3**
    
    for ($i = 0; $i < 20; $i++) {
        // Generate random malformed tokens
        $malformedTokens = [
            fake()->randomLetter() . fake()->randomNumber(5), // Random string
            fake()->word() . '.' . fake()->word(), // Fake JWT format
            str_repeat(fake()->randomLetter(), fake()->numberBetween(1, 50)), // Random length string
            fake()->uuid(), // UUID format
            base64_encode(fake()->sentence()), // Base64 encoded random text
            fake()->sha256(), // Hash-like string
            '', // Empty string
            ' ', // Whitespace
            fake()->randomElement(['invalid', 'malformed', 'wrong', 'bad']) . '-token',
            'eyJ0eXAiOiJKV1QiLCJhbGc' . fake()->randomLetter(), // Incomplete JWT-like
        ];
        
        $malformedToken = fake()->randomElement($malformedTokens);
        
        // Property: Any malformed token should be rejected with 401
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $malformedToken
        ])->getJson('/api/auth/profile');
        
        expect($response->status())->toBe(401);
        expect($response->json())->toHaveKey('message');
        expect($response->json('message'))->toBeString();
    }
})->group('property-based-test');

// **Property 28: Bearer token format accepted** - Test Bearer prefix handling
test('Property 28: Bearer token format accepted', function () {
    // **Validates: Requirements 10.6**
    
    for ($i = 0; $i < 20; $i++) {
        // Create random test user
        $user = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => hash('sha256', 'Test@123' . 'salt'),
            'roles' => [fake()->randomElement(['admin', 'employer', 'employee'])]
        ]);
        
        // Generate valid token
        $token = auth()->login($user);
        
        // Property: Correct "Bearer {token}" format should be accepted
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token
        ])->getJson('/api/auth/profile');
        
        expect($response->status())->toBe(200);
        
        // Property: Some incorrect formats should be rejected
        $incorrectFormats = [
            $token, // No Bearer prefix
            'Basic ' . $token, // Wrong auth type
            'Token ' . $token, // Wrong prefix
        ];
        
        $incorrectFormat = fake()->randomElement($incorrectFormats);
        
        $response = $this->withHeaders([
            'Authorization' => $incorrectFormat
        ])->getJson('/api/auth/profile');
        
        expect($response->status())->toBe(401);
        expect($response->json())->toHaveKey('message');
        
        // Clean up
        auth()->logout();
        $user->delete();
    }
})->group('property-based-test');

test('Property: Token validation consistency across endpoints', function () {
    // Test that token validation behaves consistently across all protected endpoints
    
    for ($i = 0; $i < 10; $i++) {
        // Create user with admin role to access all endpoints
        $user = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => hash('sha256', 'Test@123' . 'salt'),
            'roles' => ['admin'] // Admin can access everything
        ]);
        
        $token = auth()->login($user);
        
        // Test endpoints that should exist
        $endpoints = [
            '/api/auth/profile',
        ];
        
        foreach ($endpoints as $endpoint) {
            // Property: Valid tokens should not return 401 (may return 403 for role issues)
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $token
            ])->getJson($endpoint);
            
            expect($response->status())->toBe(200, "Valid token should work for {$endpoint}");
            
            // Property: Malformed tokens should always return 401
            $malformedToken = fake()->randomElement([
                'invalid-token',
                fake()->word(),
                '',
                fake()->uuid()
            ]);
            
            $response = $this->withHeaders([
                'Authorization' => 'Bearer ' . $malformedToken
            ])->getJson($endpoint);
            
            expect($response->status())->toBe(401, "Malformed token should return 401 for {$endpoint}");
            
            // Property: Missing token should return 401
            $response = $this->getJson($endpoint);
            expect($response->status())->toBe(401, "Missing token should return 401 for {$endpoint}");
        }
        
        // Clean up
        auth()->logout();
        $user->delete();
    }
})->group('property-based-test');

test('Property: Error messages are descriptive and consistent', function () {
    // Test that error messages for token validation failures are descriptive
    
    for ($i = 0; $i < 10; $i++) {
        $testScenarios = [
            [
                'name' => 'missing_token',
                'headers' => []
            ],
            [
                'name' => 'malformed_token',
                'headers' => ['Authorization' => 'Bearer ' . fake()->word()]
            ],
            [
                'name' => 'wrong_prefix',
                'headers' => ['Authorization' => 'Basic ' . fake()->word()]
            ],
            [
                'name' => 'no_prefix',
                'headers' => ['Authorization' => fake()->word()]
            ]
        ];
        
        $scenario = fake()->randomElement($testScenarios);
        
        $response = $this->withHeaders($scenario['headers'])
            ->getJson('/api/auth/profile');
        
        // Property: All token validation failures should return 401
        expect($response->status())->toBe(401);
        
        // Property: Error response should have message field
        expect($response->json())->toHaveKey('message');
        
        // Property: Error message should be descriptive (not empty, reasonable length)
        $message = $response->json('message');
        expect($message)->toBeString();
        expect($message)->not->toBeEmpty();
        expect(strlen($message))->toBeGreaterThan(3);
        expect(strlen($message))->toBeLessThan(200); // Reasonable upper bound
    }
})->group('property-based-test');

test('Property: Blacklisted tokens are consistently rejected', function () {
    // Test that blacklisted tokens are rejected across all endpoints
    
    for ($i = 0; $i < 10; $i++) {
        // Create test user
        $user = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => hash('sha256', 'Test@123' . 'salt'),
            'roles' => [fake()->randomElement(['admin', 'employer', 'employee'])]
        ]);
        
        // Generate and blacklist token
        $token = auth()->login($user);
        
        // Verify token works initially
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token
        ])->getJson('/api/auth/profile');
        expect($response->status())->toBe(200);
        
        // Blacklist the token by logging out
        $this->withHeaders([
            'Authorization' => 'Bearer ' . $token
        ])->postJson('/api/auth/logout');
        
        // Property: Blacklisted token should be rejected on all endpoints
        $endpoints = [
            '/api/auth/profile',
            '/api/auth/refresh',
        ];
        
        foreach ($endpoints as $endpoint) {
            if ($endpoint === '/api/auth/refresh') {
                $response = $this->withHeaders([
                    'Authorization' => 'Bearer ' . $token
                ])->postJson($endpoint);
            } else {
                $response = $this->withHeaders([
                    'Authorization' => 'Bearer ' . $token
                ])->getJson($endpoint);
            }
            
            expect($response->status())->toBe(401, "Blacklisted token should be rejected on {$endpoint}");
            expect($response->json())->toHaveKey('message');
        }
        
        // Clean up
        $user->delete();
    }
})->group('property-based-test');