<?php

use App\Models\Employer;
use App\Models\User;

beforeEach(function () {
    // Clear database
    User::truncate();
    Employer::truncate();
});

test('registering as employer creates pending employer record', function () {
    $response = $this->postJson('/api/auth/register', [
        'name' => 'Test Employer',
        'email' => 'employer@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role' => 'employer',
    ]);

    $response->assertStatus(201);
    $response->assertJsonStructure(['access_token', 'user']);

    // Verify user was created with employer role
    $user = User::where('email', 'employer@test.com')->first();
    expect($user)->not->toBeNull();
    expect($user->roles)->toContain('employer');
    expect($user->is_employer)->toBeFalsy(); // Should be false initially

    // Verify Employer record was created with pending status
    $employer = Employer::where('user_id', (string) $user->_id)->first();
    expect($employer)->not->toBeNull();
    expect($employer->status)->toBe(Employer::STATUS_PENDING);
});

test('employer without approval cannot create job post', function () {
    // Register as employer
    $registerResponse = $this->postJson('/api/auth/register', [
        'name' => 'Test Employer',
        'email' => 'employer@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role' => 'employer',
    ]);

    $token = $registerResponse->json('access_token');

    // Try to create job post without approval
    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/employer/jobs', [
            'title' => 'Test Job',
            'description' => 'Test Description',
            'location' => 'Test Location',
            'employment_type' => 'full-time',
            'salary_min' => 50000,
            'salary_max' => 80000,
        ]);

    $response->assertStatus(403);
    $response->assertJson([
        'error' => 'Forbidden',
        'message' => 'Your employer account is pending admin approval.',
    ]);
});

test('admin can see pending employer applications', function () {
    // Create admin user
    $admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@test.com',
        'password' => hash('sha256', 'Password@123'.'salt'),
        'roles' => ['admin'],
    ]);

    // Register employer user
    $this->postJson('/api/auth/register', [
        'name' => 'Test Employer',
        'email' => 'employer@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role' => 'employer',
    ]);

    // Login as admin
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => 'admin@test.com',
        'password' => 'Password@123',
    ]);

    $token = $loginResponse->json('access_token');

    // List pending employer applications
    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/admin/employers');

    $response->assertStatus(200);
    $response->assertJsonCount(1); // Should have 1 pending application

    $employers = $response->json();
    expect($employers[0]['status'])->toBe(Employer::STATUS_PENDING);
    expect($employers[0]['user']['email'])->toBe('employer@test.com');
});

test('admin can approve employer application', function () {
    // Create admin user
    $admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@test.com',
        'password' => hash('sha256', 'Password@123'.'salt'),
        'roles' => ['admin'],
    ]);

    // Register employer user
    $this->postJson('/api/auth/register', [
        'name' => 'Test Employer',
        'email' => 'employer@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role' => 'employer',
    ]);

    $employer = Employer::where('status', Employer::STATUS_PENDING)->first();
    expect($employer)->not->toBeNull();

    // Login as admin
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => 'admin@test.com',
        'password' => 'Password@123',
    ]);

    $token = $loginResponse->json('access_token');

    // Approve employer
    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson("/api/admin/employers/{$employer->_id}/approve");

    $response->assertStatus(200);
    $response->assertJson([
        'message' => 'Approved employer request.',
    ]);

    // Verify employer record is approved
    $employer->refresh();
    expect($employer->status)->toBe(Employer::STATUS_APPROVED);

    // Verify user has is_employer set to true
    $user = User::where('email', 'employer@test.com')->first();
    expect($user->is_employer)->toBeTrue();
    expect($user->roles)->toContain('employer');
});

test('approved employer can create job post', function () {
    // Create admin user
    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => hash('sha256', 'Password@123'.'salt'),
        'roles'    => ['admin'],
    ]);

    // Register employer user
    $registerResponse = $this->postJson('/api/auth/register', [
        'name'                  => 'Test Employer',
        'email'                 => 'employer@test.com',
        'password'              => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role'                  => 'employer',
    ]);

    $employerToken = $registerResponse->json('access_token');
    $employerUser  = User::where('email', 'employer@test.com')->first();
    $employer      = Employer::where('status', Employer::STATUS_PENDING)->first();

    // Admin approves employer
    $loginResponse = $this->postJson('/api/auth/login', [
        'email'    => 'admin@test.com',
        'password' => 'Password@123',
    ]);
    $adminToken = $loginResponse->json('access_token');

    $this->withHeader('Authorization', 'Bearer '.$adminToken)
        ->postJson("/api/admin/employers/{$employer->_id}/approve");

    // Employer must create a company profile before posting jobs
    $this->withHeader('Authorization', 'Bearer '.$employerToken)
        ->postJson('/api/employer/company', ['name' => 'Test Company Inc']);

    // Now employer can create a job post
    $response = $this->withHeader('Authorization', 'Bearer '.$employerToken)
        ->postJson('/api/employer/jobs', [
            'title'       => 'Software Engineer',
            'description' => 'We are looking for a skilled software engineer...',
            'vacancies'   => 1,
            'city'        => 'Damascus',
            'job_type'    => 'full_time',
            'communication_method' => 'by_forsa',
            'tags'        => ['PHP', 'Laravel', 'MongoDB'],
        ]);

    $response->assertStatus(201);
    $response->assertJsonPath('title', 'Software Engineer');
});

test('admin can reject employer application', function () {
    // Create admin user
    $admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@test.com',
        'password' => hash('sha256', 'Password@123'.'salt'),
        'roles' => ['admin'],
    ]);

    // Register employer user
    $this->postJson('/api/auth/register', [
        'name' => 'Test Employer',
        'email' => 'employer@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role' => 'employer',
    ]);

    $employer = Employer::where('status', Employer::STATUS_PENDING)->first();

    // Login as admin
    $loginResponse = $this->postJson('/api/auth/login', [
        'email' => 'admin@test.com',
        'password' => 'Password@123',
    ]);

    $token = $loginResponse->json('access_token');

    // Reject employer
    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson("/api/admin/employers/{$employer->_id}/reject", [
            'review_notes' => 'Insufficient documentation',
        ]);

    $response->assertStatus(200);
    $response->assertJson([
        'message' => 'Rejected employer request.',
    ]);

    // Verify employer record is rejected
    $employer->refresh();
    expect($employer->status)->toBe(Employer::STATUS_REJECTED);
    expect($employer->review_notes)->toBe('Insufficient documentation');
});

test('employer status endpoint shows pending application', function () {
    // Register employer user
    $registerResponse = $this->postJson('/api/auth/register', [
        'name' => 'Test Employer',
        'email' => 'employer@test.com',
        'password' => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role' => 'employer',
    ]);

    $token = $registerResponse->json('access_token');

    // Check status
    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->getJson('/api/employer/status');

    $response->assertStatus(200);
    $response->assertJson([
        'is_employer' => false,
        'latest' => [
            'status' => Employer::STATUS_PENDING,
        ],
    ]);
});
