<?php

use App\Models\User;

beforeEach(function () {
    User::truncate();
});

afterEach(function () {
    User::truncate();
});

// Feature: jwt-role-based-auth, Property 29: Appropriate HTTP status codes for errors
test('Property: Appropriate HTTP status codes for errors', function () {
    // Property: Authentication failures should return 401, authorization failures 403, validation errors 422
    
    for ($i = 0; $i < 10; $i++) {
        // Test authentication errors (401)
        $authTestCases = [
            // Missing token
            ['method' => 'get', 'url' => '/api/auth/profile', 'headers' => []],
            // Invalid token
            ['method' => 'get', 'url' => '/api/auth/profile', 'headers' => ['Authorization' => 'Bearer invalid-token-' . $i]],
            // Invalid credentials
            ['method' => 'post', 'url' => '/api/auth/login', 'data' => [
                'email' => fake()->email(),
                'password' => 'wrong-password-' . $i
            ]]
        ];
        
        foreach ($authTestCases as $testCase) {
            $response = $this->withHeaders($testCase['headers'] ?? [])
                ->{$testCase['method'] . 'Json'}($testCase['url'], $testCase['data'] ?? []);
            
            expect($response->status())->toBe(401, "Authentication error should return 401 for case: " . json_encode($testCase));
        }
        
        // Test authorization errors (403)
        $user = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->email(),
            'password' => hash('sha256', 'password123' . 'salt'),
            'roles' => ['employee'] // Non-admin role
        ]);
        
        $token = auth()->login($user);
        
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token
        ])->getJson('/api/admin/employers');
        
        expect($response->status())->toBe(403, "Authorization error should return 403");
        
        // Test validation errors (422)
        $validationTestCases = [
            // Invalid registration data
            ['method' => 'post', 'url' => '/api/auth/register', 'data' => [
                'name' => '', // Invalid: empty
                'email' => 'invalid-email-' . $i, // Invalid format
                'password' => '123' // Too short
            ]],
            // Invalid login data
            ['method' => 'post', 'url' => '/api/auth/login', 'data' => [
                'email' => 'not-an-email-' . $i, // Invalid format
                'password' => '' // Empty
            ]]
        ];
        
        foreach ($validationTestCases as $testCase) {
            $response = $this->{$testCase['method'] . 'Json'}($testCase['url'], $testCase['data']);
            expect($response->status())->toBe(422, "Validation error should return 422 for case: " . json_encode($testCase));
        }
        
        // Clean up user for next iteration
        $user->delete();
    }
})->group('property-based');

// Feature: jwt-role-based-auth, Property 30: Error responses are JSON with messages
test('Property: Error responses are JSON with messages', function () {
    // Property: All error responses should be JSON format with descriptive messages
    
    for ($i = 0; $i < 10; $i++) {
        $errorTestCases = [
            // Authentication errors
            ['method' => 'get', 'url' => '/api/auth/profile', 'expectedStatus' => 401],
            ['method' => 'post', 'url' => '/api/auth/login', 'data' => [
                'email' => fake()->email(),
                'password' => 'wrong-password-' . $i
            ], 'expectedStatus' => 401],
            
            // Validation errors
            ['method' => 'post', 'url' => '/api/auth/register', 'data' => [
                'name' => '',
                'email' => 'invalid-' . $i,
                'password' => '123'
            ], 'expectedStatus' => 422],
            
            ['method' => 'post', 'url' => '/api/auth/login', 'data' => [
                'email' => 'not-email-' . $i,
                'password' => ''
            ], 'expectedStatus' => 422]
        ];
        
        foreach ($errorTestCases as $testCase) {
            $response = $this->{$testCase['method'] . 'Json'}($testCase['url'], $testCase['data'] ?? []);
            
            expect($response->status())->toBe($testCase['expectedStatus']);
            
            // Property: Response should be JSON
            expect($response->headers->get('content-type'))->toContain('application/json');
            
            // Property: Response should be parseable as JSON
            $json = $response->json();
            expect($json)->toBeArray();
            expect($json)->not->toBeEmpty();
            
            // Property: Response should contain error information
            if ($testCase['expectedStatus'] === 422) {
                // Validation errors have field-specific messages
                expect($json)->toBeArray();
                expect(count($json))->toBeGreaterThan(0);
                
                // Each field should have error messages
                foreach ($json as $field => $messages) {
                    expect($field)->toBeString();
                    expect($messages)->toBeArray();
                    expect(count($messages))->toBeGreaterThan(0);
                    expect($messages[0])->toBeString();
                    expect(strlen($messages[0]))->toBeGreaterThan(5);
                }
            } else {
                // Auth/authorization errors should have error and message fields
                expect($json)->toHaveKey('error');
                expect($json)->toHaveKey('message');
                expect($json['error'])->toBeString();
                expect($json['message'])->toBeString();
                expect(strlen($json['message']))->toBeGreaterThan(5);
            }
        }
        
        // Test authorization error separately (needs authenticated user)
        $user = User::create([
            'name' => fake()->name(),
            'email' => fake()->unique()->email(),
            'password' => hash('sha256', 'password123' . 'salt'),
            'roles' => ['employee']
        ]);
        
        $token = auth()->login($user);
        
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token
        ])->getJson('/api/admin/employers');
        
        expect($response->status())->toBe(403);
        expect($response->headers->get('content-type'))->toContain('application/json');
        
        $json = $response->json();
        expect($json)->toHaveKey('error');
        expect($json)->toHaveKey('message');
        expect($json['error'])->toBe('Forbidden');
        expect($json['message'])->toBeString();
        expect(strlen($json['message']))->toBeGreaterThan(10);
        expect($json['message'])->toContain('permissions');
        
        $user->delete();
    }
})->group('property-based');