<?php

use App\Models\Employer;
use App\Models\JobPost;
use App\Models\User;

beforeEach(function () {
    // Clear database
    User::truncate();
    Employer::truncate();
    JobPost::truncate();
});

test('complete employer approval workflow - register, fetch pending, approve, create job', function () {
    // ============================================
    // STEP 1: Create Admin Account
    // ============================================
    $admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@platform.com',
        'password' => hash('sha256', 'AdminPass@123'.'salt'),
        'roles' => ['admin'],
    ]);

    expect($admin)->not->toBeNull();
    expect($admin->roles)->toContain('admin');

    // ============================================
    // STEP 2: Register New Employer Account
    // ============================================
    $employerRegisterResponse = $this->postJson('/api/auth/register', [
        'name' => 'Test Employer Company',
        'email' => 'employer@company.com',
        'password' => 'EmployerPass@123',
        'password_confirmation' => 'EmployerPass@123',
        'role' => 'employer',
    ]);

    $employerRegisterResponse->assertStatus(201);
    $employerRegisterResponse->assertJsonStructure([
        'access_token',
        'user' => ['id', 'email', 'roles'],
    ]);

    $employerToken = $employerRegisterResponse->json('access_token');
    $employerUserId = $employerRegisterResponse->json('user.id');

    // Verify employer was created with correct data
    expect($employerRegisterResponse->json('user.email'))->toBe('employer@company.com');
    expect($employerRegisterResponse->json('user.roles'))->toContain('employer');
    expect($employerRegisterResponse->json('user.is_employer'))->toBeFalsy();

    // Verify Employer record was auto-created
    $employerRecord = Employer::where('user_id', $employerUserId)->first();
    expect($employerRecord)->not->toBeNull();
    expect($employerRecord->status)->toBe(Employer::STATUS_PENDING);

    // ============================================
    // STEP 3: Admin Logs In
    // ============================================
    $adminLoginResponse = $this->postJson('/api/auth/login', [
        'email' => 'admin@platform.com',
        'password' => 'AdminPass@123',
    ]);

    $adminLoginResponse->assertStatus(200);
    $adminToken = $adminLoginResponse->json('access_token');
    expect($adminToken)->not->toBeEmpty();

    // ============================================
    // STEP 4: Admin Fetches Pending Employer Applications
    // ============================================
    $pendingApplicationsResponse = $this->withHeader('Authorization', 'Bearer '.$adminToken)
        ->getJson('/api/admin/employers');

    $pendingApplicationsResponse->assertStatus(200);
    $pendingApplicationsResponse->assertJsonCount(1); // Should have exactly 1 pending

    $pendingApplications = $pendingApplicationsResponse->json();

    // Verify the pending application is our employer
    expect($pendingApplications[0]['status'])->toBe(Employer::STATUS_PENDING);
    expect($pendingApplications[0]['user']['email'])->toBe('employer@company.com');
    expect($pendingApplications[0]['user_id'])->toBe($employerUserId);

    // Store the employer application ID
    $employerApplicationId = $pendingApplications[0]['id'];
    expect($employerApplicationId)->not->toBeEmpty();

    // ============================================
    // STEP 5: Verify Employer Cannot Create Job Post Yet
    // ============================================
    $jobPostAttempt1 = $this->withHeader('Authorization', 'Bearer '.$employerToken)
        ->postJson('/api/employer/jobs', [
            'title' => 'Software Engineer',
            'description' => 'Looking for a talented engineer',
            'requirements' => 'Minimum 3 years experience',
            'company_name' => 'Test Company Inc',
            'job_type' => 'full_time',
        ]);

    $jobPostAttempt1->assertStatus(403);
    $jobPostAttempt1->assertJson([
        'error' => 'Forbidden',
        'message' => 'Your employer account is pending admin approval.',
    ]);

    // ============================================
    // STEP 6: Admin Approves Employer Application
    // ============================================
    $approvalResponse = $this->withHeader('Authorization', 'Bearer '.$adminToken)
        ->postJson("/api/admin/employers/{$employerApplicationId}/approve");

    $approvalResponse->assertStatus(200);
    $approvalResponse->assertJson([
        'message' => 'Approved employer request.',
    ]);

    // Verify employer status changed to approved
    $employerRecord->refresh();
    expect($employerRecord->status)->toBe(Employer::STATUS_APPROVED);

    // Verify user's is_employer flag is now true
    $employerUser = User::find($employerUserId);
    expect($employerUser->is_employer)->toBeTrue();
    expect($employerUser->roles)->toContain('employer');

    // ============================================
    // STEP 7: Verify Pending List is Now Empty
    // ============================================
    $pendingAfterApproval = $this->withHeader('Authorization', 'Bearer '.$adminToken)
        ->getJson('/api/admin/employers');

    $pendingAfterApproval->assertStatus(200);
    $pendingAfterApproval->assertJsonCount(0); // Should be empty now

    // ============================================
    // STEP 8: Employer Creates Job Post Successfully
    // ============================================
    $jobPostAttempt2 = $this->withHeader('Authorization', 'Bearer '.$employerToken)
        ->postJson('/api/employer/jobs', [
            'title' => 'Senior PHP Developer',
            'description' => 'We are seeking an experienced PHP developer to join our team.',
            'requirements' => 'Minimum 5 years of PHP experience, Laravel expertise required',
            'company_name' => 'Test Company Inc',
            'company_logo' => 'https://example.com/logo.png',
            'job_type' => 'full_time',
            'work_mode' => 'remote',
            'experience_level' => 'senior',
            'location' => 'Remote',
            'category' => 'Engineering',
            'salary_range' => [
                'min' => 80000,
                'max' => 120000,
                'currency' => 'USD',
            ],
            'tags' => ['PHP', 'Laravel', 'MongoDB', 'REST API'],
        ]);

    $jobPostAttempt2->assertStatus(201);
    $jobPostAttempt2->assertJsonStructure([
        'id',
        'job_id',
        'title',
        'description',
        'requirements',
        'company_name',
        'employer_id',
        'is_active',
    ]);

    // Verify job post data
    expect($jobPostAttempt2->json('title'))->toBe('Senior PHP Developer');
    expect($jobPostAttempt2->json('company_name'))->toBe('Test Company Inc');
    expect($jobPostAttempt2->json('job_type'))->toBe('full_time');
    expect($jobPostAttempt2->json('is_active'))->toBeTrue();
    expect($jobPostAttempt2->json('employer_id'))->toBe($employerUserId);

    $jobPostId = $jobPostAttempt2->json('id');

    // ============================================
    // STEP 9: Verify Job Post Exists in Database
    // ============================================
    $jobPostInDb = JobPost::find($jobPostId);
    expect($jobPostInDb)->not->toBeNull();
    expect($jobPostInDb->title)->toBe('Senior PHP Developer');
    expect($jobPostInDb->employer_id)->toBe($employerUserId);
    expect($jobPostInDb->is_active)->toBeTrue();
    expect($jobPostInDb->tags)->toContain('PHP');
    expect($jobPostInDb->tags)->toContain('Laravel');

    // ============================================
    // STEP 10: Verify Job Post is Publicly Visible
    // ============================================
    $publicJobListResponse = $this->getJson('/api/jobs');
    $publicJobListResponse->assertStatus(200);

    $jobs = $publicJobListResponse->json('data');
    expect($jobs)->toHaveCount(1);
    expect($jobs[0]['id'])->toBe($jobPostId);
    expect($jobs[0]['title'])->toBe('Senior PHP Developer');

    // ============================================
    // STEP 11: Employer Can View Their Job Posts
    // ============================================
    $myJobsResponse = $this->withHeader('Authorization', 'Bearer '.$employerToken)
        ->getJson('/api/employer/jobs');

    $myJobsResponse->assertStatus(200);

    $myJobs = $myJobsResponse->json();
    expect($myJobs)->toHaveCount(1);
    expect($myJobs[0]['id'])->toBe($jobPostId);
    expect($myJobs[0])->toHaveKey('application_count');
});

test('complete workflow with multiple employers - verify correct employer is approved', function () {
    // Create admin
    $admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@platform.com',
        'password' => hash('sha256', 'AdminPass@123'.'salt'),
        'roles' => ['admin'],
    ]);

    // Register first employer
    $employer1Response = $this->postJson('/api/auth/register', [
        'name' => 'First Employer',
        'email' => 'employer1@company.com',
        'password' => 'Pass@123',
        'password_confirmation' => 'Pass@123',
        'role' => 'employer',
    ]);
    $employer1Token = $employer1Response->json('access_token');
    $employer1UserId = $employer1Response->json('user.id');

    // Register second employer
    $employer2Response = $this->postJson('/api/auth/register', [
        'name' => 'Second Employer',
        'email' => 'employer2@company.com',
        'password' => 'Pass@123',
        'password_confirmation' => 'Pass@123',
        'role' => 'employer',
    ]);
    $employer2Token = $employer2Response->json('access_token');
    $employer2UserId = $employer2Response->json('user.id');

    // Admin logs in
    $adminLoginResponse = $this->postJson('/api/auth/login', [
        'email' => 'admin@platform.com',
        'password' => 'AdminPass@123',
    ]);
    $adminToken = $adminLoginResponse->json('access_token');

    // Admin fetches pending applications - should have 2
    $pendingResponse = $this->withHeader('Authorization', 'Bearer '.$adminToken)
        ->getJson('/api/admin/employers');

    $pendingResponse->assertStatus(200);
    $pendingResponse->assertJsonCount(2);

    $pending = $pendingResponse->json();

    // Find employer1's application
    $employer1Application = collect($pending)->firstWhere('user.email', 'employer1@company.com');
    expect($employer1Application)->not->toBeNull();
    expect($employer1Application['status'])->toBe(Employer::STATUS_PENDING);

    // Approve only employer1
    $approveResponse = $this->withHeader('Authorization', 'Bearer '.$adminToken)
        ->postJson("/api/admin/employers/{$employer1Application['id']}/approve");

    $approveResponse->assertStatus(200);

    // Employer1 can create job post
    $job1Response = $this->withHeader('Authorization', 'Bearer '.$employer1Token)
        ->postJson('/api/employer/jobs', [
            'title' => 'Job by Employer 1',
            'description' => 'Description',
            'requirements' => 'Requirements',
            'company_name' => 'Company 1',
            'job_type' => 'full_time',
        ]);

    $job1Response->assertStatus(201);
    expect($job1Response->json('title'))->toBe('Job by Employer 1');

    // Employer2 still cannot create job post
    $job2Response = $this->withHeader('Authorization', 'Bearer '.$employer2Token)
        ->postJson('/api/employer/jobs', [
            'title' => 'Job by Employer 2',
            'description' => 'Description',
            'requirements' => 'Requirements',
            'company_name' => 'Company 2',
            'job_type' => 'full_time',
        ]);

    $job2Response->assertStatus(403);
    $job2Response->assertJson([
        'error' => 'Forbidden',
        'message' => 'Your employer account is pending admin approval.',
    ]);

    // Verify only 1 pending application remains
    $pendingAfterResponse = $this->withHeader('Authorization', 'Bearer '.$adminToken)
        ->getJson('/api/admin/employers');

    $pendingAfterResponse->assertStatus(200);
    $pendingAfterResponse->assertJsonCount(1);

    $remainingPending = $pendingAfterResponse->json();
    expect($remainingPending[0]['user']['email'])->toBe('employer2@company.com');
});

test('rejected employer cannot create job posts', function () {
    // Create admin
    $admin = User::create([
        'name' => 'Admin User',
        'email' => 'admin@platform.com',
        'password' => hash('sha256', 'AdminPass@123'.'salt'),
        'roles' => ['admin'],
    ]);

    // Register employer
    $employerResponse = $this->postJson('/api/auth/register', [
        'name' => 'Test Employer',
        'email' => 'employer@company.com',
        'password' => 'Pass@123',
        'password_confirmation' => 'Pass@123',
        'role' => 'employer',
    ]);
    $employerToken = $employerResponse->json('access_token');

    // Admin logs in and fetches pending
    $adminLoginResponse = $this->postJson('/api/auth/login', [
        'email' => 'admin@platform.com',
        'password' => 'AdminPass@123',
    ]);
    $adminToken = $adminLoginResponse->json('access_token');

    $pendingResponse = $this->withHeader('Authorization', 'Bearer '.$adminToken)
        ->getJson('/api/admin/employers');

    $pending = $pendingResponse->json();
    $employerApplicationId = $pending[0]['id'];

    // Admin rejects the application
    $rejectResponse = $this->withHeader('Authorization', 'Bearer '.$adminToken)
        ->postJson("/api/admin/employers/{$employerApplicationId}/reject", [
            'review_notes' => 'Insufficient documentation provided',
        ]);

    $rejectResponse->assertStatus(200);
    $rejectResponse->assertJson([
        'message' => 'Rejected employer request.',
    ]);

    // Employer still cannot create job post
    $jobResponse = $this->withHeader('Authorization', 'Bearer '.$employerToken)
        ->postJson('/api/employer/jobs', [
            'title' => 'Test Job',
            'description' => 'Description',
            'requirements' => 'Requirements',
            'company_name' => 'Test Company',
            'job_type' => 'full_time',
        ]);

    $jobResponse->assertStatus(403);

    // Verify no pending applications remain
    $pendingAfterResponse = $this->withHeader('Authorization', 'Bearer '.$adminToken)
        ->getJson('/api/admin/employers');

    $pendingAfterResponse->assertStatus(200);
    $pendingAfterResponse->assertJsonCount(0);
});
