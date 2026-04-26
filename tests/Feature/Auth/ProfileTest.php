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

test('authenticated user can retrieve profile', function () {
    // Create a user with roles
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => hash('sha256', 'password' . 'salt'),
        'roles' => ['employee', 'employer']
    ]);

    // Login to get token
    $token = auth()->login($user);

    // Get profile
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token
    ])->getJson('/api/auth/profile');

    $response->assertStatus(200);
    
    // Check response structure
    $data = $response->json();
    
    // Verify required fields are present (Requirements 9.1)
    expect($data)->toHaveKeys(['id', 'name', 'email', 'roles']);
    expect($data['name'])->toBe('Test User');
    expect($data['email'])->toBe('test@example.com');
    expect($data['roles'])->toBe(['employee', 'employer']);
    
    // Verify password is not in response (Requirements 9.4)
    expect($data)->not->toHaveKey('password');
});

test('authenticated admin user can retrieve profile', function () {
    // Create an admin user
    $user = User::create([
        'name' => 'Admin User',
        'email' => 'admin@example.com',
        'password' => hash('sha256', 'adminpass' . 'salt'),
        'roles' => ['admin']
    ]);

    // Login to get token
    $token = auth()->login($user);

    // Get profile
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token
    ])->getJson('/api/auth/profile');

    $response->assertStatus(200);
    
    // Check response structure
    $data = $response->json();
    
    // Verify required fields are present (Requirements 9.1)
    expect($data)->toHaveKeys(['id', 'name', 'email', 'roles']);
    expect($data['name'])->toBe('Admin User');
    expect($data['email'])->toBe('admin@example.com');
    expect($data['roles'])->toBe(['admin']);
    
    // Verify password is not in response (Requirements 9.4)
    expect($data)->not->toHaveKey('password');
});

test('authenticated employee user can retrieve profile', function () {
    // Create an employee user
    $user = User::create([
        'name' => 'Employee User',
        'email' => 'employee@example.com',
        'password' => hash('sha256', 'emppass' . 'salt'),
        'roles' => ['employee']
    ]);

    // Login to get token
    $token = auth()->login($user);

    // Get profile
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token
    ])->getJson('/api/auth/profile');

    $response->assertStatus(200);
    
    // Check response structure
    $data = $response->json();
    
    // Verify required fields are present (Requirements 9.1)
    expect($data)->toHaveKeys(['id', 'name', 'email', 'roles']);
    expect($data['name'])->toBe('Employee User');
    expect($data['email'])->toBe('employee@example.com');
    expect($data['roles'])->toBe(['employee']);
    
    // Verify password is not in response (Requirements 9.4)
    expect($data)->not->toHaveKey('password');
});

test('authenticated employer user can retrieve profile', function () {
    // Create an employer user
    $user = User::create([
        'name' => 'Employer User',
        'email' => 'employer@example.com',
        'password' => hash('sha256', 'emppass' . 'salt'),
        'roles' => ['employer']
    ]);

    // Login to get token
    $token = auth()->login($user);

    // Get profile
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token
    ])->getJson('/api/auth/profile');

    $response->assertStatus(200);
    
    // Check response structure
    $data = $response->json();
    
    // Verify required fields are present (Requirements 9.1)
    expect($data)->toHaveKeys(['id', 'name', 'email', 'roles']);
    expect($data['name'])->toBe('Employer User');
    expect($data['email'])->toBe('employer@example.com');
    expect($data['roles'])->toBe(['employer']);
    
    // Verify password is not in response (Requirements 9.4)
    expect($data)->not->toHaveKey('password');
});

test('unauthenticated request to profile returns 401', function () {
    // Requirements 9.2: Unauthenticated user requests should be rejected
    $response = $this->getJson('/api/auth/profile');
    
    $response->assertStatus(401);
});

test('profile response structure contains all required fields', function () {
    // Create a user with multiple roles
    $user = User::create([
        'name' => 'Multi Role User',
        'email' => 'multi@example.com',
        'password' => hash('sha256', 'multipass' . 'salt'),
        'roles' => ['admin', 'employer', 'employee']
    ]);

    // Login to get token
    $token = auth()->login($user);

    // Get profile
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token
    ])->getJson('/api/auth/profile');

    $response->assertStatus(200);
    
    // Check response structure (Requirements 9.1)
    $data = $response->json();
    
    // Verify all required fields are present
    expect($data)->toHaveKeys(['id', 'name', 'email', 'roles']);
    
    // Verify data types
    expect($data['id'])->toBeString();
    expect($data['name'])->toBeString();
    expect($data['email'])->toBeString();
    expect($data['roles'])->toBeArray();
    
    // Verify roles array contains expected values
    expect($data['roles'])->toContain('admin');
    expect($data['roles'])->toContain('employer');
    expect($data['roles'])->toContain('employee');
});

test('password field is never included in profile response', function () {
    // Create users with different roles to test password exclusion
    $users = [
        [
            'name' => 'Admin Test',
            'email' => 'admin.test@example.com',
            'password' => hash('sha256', 'admintest' . 'salt'),
            'roles' => ['admin']
        ],
        [
            'name' => 'Employer Test',
            'email' => 'employer.test@example.com',
            'password' => hash('sha256', 'employertest' . 'salt'),
            'roles' => ['employer']
        ],
        [
            'name' => 'Employee Test',
            'email' => 'employee.test@example.com',
            'password' => hash('sha256', 'employeetest' . 'salt'),
            'roles' => ['employee']
        ]
    ];

    foreach ($users as $userData) {
        $user = User::create($userData);
        $token = auth()->login($user);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token
        ])->getJson('/api/auth/profile');

        $response->assertStatus(200);
        
        // Verify password is not in response (Requirements 9.4)
        $data = $response->json();
        expect($data)->not->toHaveKey('password');
        
        // Also verify remember_token is not included (should be hidden)
        expect($data)->not->toHaveKey('remember_token');
        
        // Logout to clean up token
        auth()->logout();
        
        // Clean up user
        $user->delete();
    }
});

test('profile request with invalid token returns 401', function () {
    // Test with malformed token
    $response = $this->withHeaders([
        'Authorization' => 'Bearer invalid-token-format'
    ])->getJson('/api/auth/profile');
    
    $response->assertStatus(401);
});

test('profile request with expired token returns 401', function () {
    // Create a user
    $user = User::create([
        'name' => 'Test User',
        'email' => 'test.expired@example.com',
        'password' => hash('sha256', 'password' . 'salt'),
        'roles' => ['employee']
    ]);

    // Login to get token
    $token = auth()->login($user);
    
    // Logout to invalidate the token (simulating expiration)
    auth()->logout();

    // Try to access profile with invalidated token
    $response = $this->withHeaders([
        'Authorization' => 'Bearer ' . $token
    ])->getJson('/api/auth/profile');
    
    $response->assertStatus(401);
});