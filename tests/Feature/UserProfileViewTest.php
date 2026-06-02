<?php

use App\Models\CompanyProfile;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// Note: Tests use unique data to avoid conflicts instead of truncating collections

test('job seeker can view employer full profile', function () {
    // Register job seeker
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Alice Seeker',
        'email'    => 'alice@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerToken = $seekerRes->json('token');

    // Register employer
    $employerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Bob Employer',
        'email'    => 'bob@test.com',
        'password' => 'password123',
        'roles'    => ['employer'],
    ]);
    $employerToken = $employerRes->json('token');
    $employerId    = $employerRes->json('user.id');

    // Admin approves employer
    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $application = Employer::where('user_id', $employerId)->first();
    $this->withHeader('Authorization', "Bearer $adminToken")
        ->putJson("/api/admin/employers/{$application->_id}/approve")
        ->assertOk();

    // Employer creates company profile
    $this->withHeader('Authorization', "Bearer $employerToken")
        ->putJson('/api/employer/company-profile', [
            'company_name' => 'Acme Corp',
            'industry'     => 'Tech',
            'website'      => 'https://acme.com',
        ])
        ->assertOk();

    // Employer creates job post
    $this->withHeader('Authorization', "Bearer $employerToken")
        ->postJson('/api/jobs', [
            'title'       => 'Senior Developer',
            'description' => 'Great role',
            'company_name'=> 'Acme Corp',
            'location'    => 'Beirut',
        ])
        ->assertCreated();

    // Job seeker views employer profile
    $res = $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson("/api/users/$employerId")
        ->assertOk();

    expect($res->json('user.name'))->toBe('Bob Employer');
    expect($res->json('user.profile.company_name'))->toBe('Acme Corp');
    expect($res->json('user.profile.open_positions_count'))->toBe(1);
});

test('employer can view job seeker full profile including ai fields', function () {
    // Register job seeker
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Charlie Seeker',
        'email'    => 'charlie@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerToken = $seekerRes->json('token');
    $seekerId    = $seekerRes->json('user.id');

    // Update profile with AI fields
    JobSeekerProfile::where('user_id', $seekerId)->update([
        'ai_skills'  => ['PHP', 'Laravel'],
        'ai_summary' => 'Experienced developer',
        'ats_score'  => 85,
    ]);

    // Register and approve employer
    $employerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Dave Employer',
        'email'    => 'dave@test.com',
        'password' => 'password123',
        'roles'    => ['employer'],
    ]);
    $employerToken = $employerRes->json('token');
    $employerId    = $employerRes->json('user.id');

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $application = Employer::where('user_id', $employerId)->first();
    $this->withHeader('Authorization', "Bearer $adminToken")
        ->putJson("/api/admin/employers/{$application->_id}/approve")
        ->assertOk();

    // Employer views job seeker profile
    $res = $this->withHeader('Authorization', "Bearer $employerToken")
        ->getJson("/api/users/$seekerId")
        ->assertOk();

    expect($res->json('user.name'))->toBe('Charlie Seeker');
    expect($res->json('user.profile.ai_skills'))->toBe(['PHP', 'Laravel']);
    expect($res->json('user.profile.ai_summary'))->toBe('Experienced developer');
    expect($res->json('user.profile.ats_score'))->toBe(85);
});

test('admin can view any user profile', function () {
    // Register job seeker
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Eve Seeker',
        'email'    => 'eve@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerId = $seekerRes->json('user.id');

    // Create admin
    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    // Admin views job seeker
    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson("/api/users/$seekerId")
        ->assertOk();

    expect($res->json('user.name'))->toBe('Eve Seeker');
    expect($res->json('user.email'))->toBe('eve@test.com');
});

test('returns 404 for non-existent user', function () {
    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/users/507f1f77bcf86cd799439011')
        ->assertNotFound()
        ->assertJson(['message' => 'User not found']);
});

test('admin can list all users with pagination', function () {
    // Create multiple users
    User::create(['name' => 'User 1', 'email' => 'u1@test.com', 'password' => Hash::make('pass'), 'roles' => ['employee']]);
    User::create(['name' => 'User 2', 'email' => 'u2@test.com', 'password' => Hash::make('pass'), 'roles' => ['employer']]);
    User::create(['name' => 'User 3', 'email' => 'u3@test.com', 'password' => Hash::make('pass'), 'roles' => ['employee']]);

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users?per_page=2')
        ->assertOk();

    expect($res->json('total'))->toBe(4);
    expect($res->json('per_page'))->toBe(2);
    expect($res->json('total_pages'))->toBe(2);
    expect(count($res->json('data')))->toBe(2);
});

test('admin can list all job seekers with profiles', function () {
    // Create job seekers
    $seeker1 = User::create(['name' => 'Seeker 1', 'email' => 's1@test.com', 'password' => Hash::make('pass'), 'roles' => ['employee']]);
    JobSeekerProfile::create(['user_id' => (string) $seeker1->_id, 'ai_skills' => ['PHP']]);

    $seeker2 = User::create(['name' => 'Seeker 2', 'email' => 's2@test.com', 'password' => Hash::make('pass'), 'roles' => ['employee']]);
    JobSeekerProfile::create(['user_id' => (string) $seeker2->_id, 'ai_skills' => ['JavaScript']]);

    // Create employer (should not appear)
    User::create(['name' => 'Employer', 'email' => 'emp@test.com', 'password' => Hash::make('pass'), 'roles' => ['employer']]);

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users/seekers')
        ->assertOk();

    expect($res->json('total'))->toBe(2);
    expect($res->json('data.0.profile.ai_skills'))->toBe(['PHP']);
    expect($res->json('data.1.profile.ai_skills'))->toBe(['JavaScript']);
});

test('admin can list all employers with company profiles', function () {
    // Create employers
    $emp1 = User::create(['name' => 'Employer 1', 'email' => 'e1@test.com', 'password' => Hash::make('pass'), 'roles' => ['employer']]);
    CompanyProfile::create(['employer_id' => (string) $emp1->_id, 'company_name' => 'Company A']);
    JobPost::create(['employer_id' => (string) $emp1->_id, 'title' => 'Job 1', 'description' => 'Desc', 'company_name' => 'Company A', 'is_active' => true]);

    $emp2 = User::create(['name' => 'Employer 2', 'email' => 'e2@test.com', 'password' => Hash::make('pass'), 'roles' => ['employer']]);
    CompanyProfile::create(['employer_id' => (string) $emp2->_id, 'company_name' => 'Company B']);

    // Create job seeker (should not appear)
    User::create(['name' => 'Seeker', 'email' => 'seeker@test.com', 'password' => Hash::make('pass'), 'roles' => ['employee']]);

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users/employers')
        ->assertOk();

    expect($res->json('total'))->toBe(2);
    expect($res->json('data.0.profile.company_name'))->toBe('Company A');
    expect($res->json('data.0.profile.open_positions_count'))->toBe(1);
    expect($res->json('data.1.profile.company_name'))->toBe('Company B');
    expect($res->json('data.1.profile.open_positions_count'))->toBe(0);
});

test('non-admin cannot access admin list endpoints', function () {
    // Create job seeker
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker',
        'email'    => 'seeker@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerToken = $seekerRes->json('token');

    // Try to access admin endpoints
    $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson('/api/admin/users')
        ->assertForbidden();

    $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson('/api/admin/users/seekers')
        ->assertForbidden();

    $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson('/api/admin/users/employers')
        ->assertForbidden();
});

test('unauthenticated user cannot view profiles', function () {
    $this->getJson('/api/users/507f1f77bcf86cd799439011')
        ->assertUnauthorized();
});

test('employer without company profile returns null profile', function () {
    // Create employer without company profile
    $empRes = $this->postJson('/api/auth/register', [
        'name'     => 'Employer',
        'email'    => 'emp@test.com',
        'password' => 'password123',
        'roles'    => ['employer'],
    ]);
    $empId = $empRes->json('user.id');

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson("/api/users/$empId")
        ->assertOk();

    expect($res->json('user.profile'))->toBeNull();
});

test('job seeker without profile returns null profile', function () {
    // Create job seeker and delete profile
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker',
        'email'    => 'seeker@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerId = $seekerRes->json('user.id');

    JobSeekerProfile::where('user_id', $seekerId)->delete();

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson("/api/users/$seekerId")
        ->assertOk();

    expect($res->json('user.profile'))->toBeNull();
});

test('admin list seekers pagination works correctly', function () {
    // Create 25 job seekers
    for ($i = 1; $i <= 25; $i++) {
        $user = User::create([
            'name'     => "Seeker $i",
            'email'    => "seeker$i@test.com",
            'password' => Hash::make('pass'),
            'roles'    => ['employee'],
        ]);
        JobSeekerProfile::create(['user_id' => (string) $user->_id]);
    }

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    // Page 1
    $res1 = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users/seekers?per_page=10&page=1')
        ->assertOk();

    expect($res1->json('total'))->toBe(25);
    expect($res1->json('per_page'))->toBe(10);
    expect($res1->json('current_page'))->toBe(1);
    expect($res1->json('total_pages'))->toBe(3);
    expect(count($res1->json('data')))->toBe(10);
    expect($res1->json('next_page'))->toBe(2);
    expect($res1->json('prev_page'))->toBeNull();

    // Page 2
    $res2 = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users/seekers?per_page=10&page=2')
        ->assertOk();

    expect($res2->json('current_page'))->toBe(2);
    expect($res2->json('next_page'))->toBe(3);
    expect($res2->json('prev_page'))->toBe(1);

    // Page 3 (last page with 5 items)
    $res3 = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users/seekers?per_page=10&page=3')
        ->assertOk();

    expect($res3->json('current_page'))->toBe(3);
    expect(count($res3->json('data')))->toBe(5);
    expect($res3->json('next_page'))->toBeNull();
    expect($res3->json('prev_page'))->toBe(2);
});

test('admin list employers pagination works correctly', function () {
    // Create 18 employers
    for ($i = 1; $i <= 18; $i++) {
        $user = User::create([
            'name'     => "Employer $i",
            'email'    => "emp$i@test.com",
            'password' => Hash::make('pass'),
            'roles'    => ['employer'],
        ]);
        CompanyProfile::create(['employer_id' => (string) $user->_id, 'company_name' => "Company $i"]);
    }

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users/employers?per_page=5')
        ->assertOk();

    expect($res->json('total'))->toBe(18);
    expect($res->json('per_page'))->toBe(5);
    expect($res->json('total_pages'))->toBe(4);
    expect(count($res->json('data')))->toBe(5);
});

test('user profile view does not expose password field', function () {
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker',
        'email'    => 'seeker@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerToken = $seekerRes->json('token');
    $seekerId    = $seekerRes->json('user.id');

    $res = $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson("/api/users/$seekerId")
        ->assertOk();

    expect($res->json('user'))->not->toHaveKey('password');
});

test('admin can view user with multiple roles', function () {
    // Create user with both employee and employer roles
    $user = User::create([
        'name'     => 'Multi Role User',
        'email'    => 'multi@test.com',
        'password' => Hash::make('pass'),
        'roles'    => ['employee', 'employer'],
    ]);

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson("/api/users/{$user->_id}")
        ->assertOk();

    expect($res->json('user.name'))->toBe('Multi Role User');
    expect($res->json('user.roles'))->toContain('employee');
    expect($res->json('user.roles'))->toContain('employer');
});


test('admin list all users respects per_page limit of 100', function () {
    // Create 150 users
    for ($i = 1; $i <= 150; $i++) {
        User::create([
            'name'     => "User $i",
            'email'    => "user$i@test.com",
            'password' => Hash::make('pass'),
            'roles'    => ['employee'],
        ]);
    }

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    // Request 200 per page, should be capped at 100
    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users?per_page=200')
        ->assertOk();

    expect($res->json('per_page'))->toBe(100);
    expect(count($res->json('data')))->toBe(100);
});

test('viewing profile includes correct id format', function () {
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker',
        'email'    => 'seeker@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerToken = $seekerRes->json('token');
    $seekerId    = $seekerRes->json('user.id');

    $res = $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson("/api/users/$seekerId")
        ->assertOk();

    expect($res->json('user.id'))->toBeString();
    expect(strlen($res->json('user.id')))->toBe(24); // MongoDB ObjectId length
});

test('employer profile includes all active job posts count', function () {
    // Create employer
    $empRes = $this->postJson('/api/auth/register', [
        'name'     => 'Employer',
        'email'    => 'emp@test.com',
        'password' => 'password123',
        'roles'    => ['employer'],
    ]);
    $empToken = $empRes->json('token');
    $empId    = $empRes->json('user.id');

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    $app = Employer::where('user_id', $empId)->first();
    $this->withHeader('Authorization', "Bearer $adminToken")
        ->putJson("/api/admin/employers/{$app->_id}/approve")
        ->assertOk();

    // Create company profile
    $this->withHeader('Authorization', "Bearer $empToken")
        ->putJson('/api/employer/company-profile', [
            'company_name' => 'Test Company',
        ])
        ->assertOk();

    // Create 3 active and 2 inactive jobs
    for ($i = 1; $i <= 3; $i++) {
        $this->withHeader('Authorization', "Bearer $empToken")
            ->postJson('/api/jobs', [
                'title'       => "Active Job $i",
                'description' => 'Desc',
                'company_name'=> 'Test Company',
            ])
            ->assertCreated();
    }

    for ($i = 1; $i <= 2; $i++) {
        $jobRes = $this->withHeader('Authorization', "Bearer $empToken")
            ->postJson('/api/jobs', [
                'title'       => "Inactive Job $i",
                'description' => 'Desc',
                'company_name'=> 'Test Company',
            ])
            ->assertCreated();
        
        $this->withHeader('Authorization', "Bearer $empToken")
            ->putJson("/api/jobs/{$jobRes->json('job.id')}/deactivate")
            ->assertOk();
    }

    // View profile
    $res = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson("/api/users/$empId")
        ->assertOk();

    expect($res->json('user.profile.open_positions_count'))->toBe(3);
});

test('concurrent requests to view same profile return consistent data', function () {
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker',
        'email'    => 'seeker@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerToken = $seekerRes->json('token');
    $seekerId    = $seekerRes->json('user.id');

    // Make 5 concurrent requests
    $responses = [];
    for ($i = 0; $i < 5; $i++) {
        $responses[] = $this->withHeader('Authorization', "Bearer $seekerToken")
            ->getJson("/api/users/$seekerId")
            ->assertOk()
            ->json();
    }

    // All responses should be identical
    $firstResponse = $responses[0];
    foreach ($responses as $response) {
        expect($response)->toBe($firstResponse);
    }
});


test('complete end-to-end profile viewing workflow across all roles', function () {
    // Create admin
    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    // Create 3 job seekers with varying profiles
    $seekers = [];
    for ($i = 1; $i <= 3; $i++) {
        $seeker = User::create([
            'name'     => "Seeker $i",
            'email'    => "seeker$i@test.com",
            'password' => Hash::make('pass'),
            'roles'    => ['employee'],
        ]);
        JobSeekerProfile::where('user_id', (string) $seeker->_id)->update([
            'ai_skills'  => ['PHP', 'Laravel', "Skill$i"],
            'ats_score'  => 70 + ($i * 5),
            'ai_summary' => "Summary for seeker $i",
        ]);
        $seekers[] = $seeker;
    }

    // Create 2 employers with companies
    $employers = [];
    for ($i = 1; $i <= 2; $i++) {
        $emp = User::create([
            'name'     => "Employer $i",
            'email'    => "emp$i@test.com",
            'password' => Hash::make('pass'),
            'roles'    => ['employer'],
        ]);
        CompanyProfile::create([
            'employer_id'  => (string) $emp->_id,
            'company_name' => "Company $i",
            'industry'     => 'Tech',
        ]);
        Employer::create(['user_id' => (string) $emp->_id, 'status' => 'approved']);
        
        // Create jobs for each employer
        for ($j = 1; $j <= $i; $j++) {
            JobPost::create([
                'employer_id'  => (string) $emp->_id,
                'title'        => "Job $i-$j",
                'description'  => 'Desc',
                'company_name' => "Company $i",
                'is_active'    => true,
            ]);
        }
        $employers[] = $emp;
    }

    // Test 1: Admin views all users
    $allUsersRes = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users')
        ->assertOk();
    expect($allUsersRes->json('total'))->toBe(6); // 1 admin + 3 seekers + 2 employers

    // Test 2: Admin views all seekers
    $seekersRes = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users/seekers')
        ->assertOk();
    expect($seekersRes->json('total'))->toBe(3);
    expect($seekersRes->json('data.0.profile.ai_skills'))->toContain('PHP');

    // Test 3: Admin views all employers
    $employersRes = $this->withHeader('Authorization', "Bearer $adminToken")
        ->getJson('/api/admin/users/employers')
        ->assertOk();
    expect($employersRes->json('total'))->toBe(2);
    expect($employersRes->json('data.0.profile.open_positions_count'))->toBeGreaterThan(0);

    // Test 4: Each seeker views each employer
    foreach ($seekers as $seeker) {
        $seekerToken = auth()->login($seeker);
        foreach ($employers as $employer) {
            $res = $this->withHeader('Authorization', "Bearer $seekerToken")
                ->getJson("/api/users/{$employer->_id}")
                ->assertOk();
            expect($res->json('user.profile.company_name'))->toContain('Company');
        }
    }

    // Test 5: Each employer views each seeker
    foreach ($employers as $employer) {
        $empToken = auth()->login($employer);
        foreach ($seekers as $seeker) {
            $res = $this->withHeader('Authorization', "Bearer $empToken")
                ->getJson("/api/users/{$seeker->_id}")
                ->assertOk();
            expect($res->json('user.profile.ai_skills'))->toBeArray();
        }
    }
});

test('admin list endpoints handle large datasets efficiently', function () {
    // Create 100 job seekers
    for ($i = 1; $i <= 100; $i++) {
        $user = User::create([
            'name'     => "Seeker $i",
            'email'    => "seeker$i@test.com",
            'password' => Hash::make('pass'),
            'roles'    => ['employee'],
        ]);
        JobSeekerProfile::create([
            'user_id'   => (string) $user->_id,
            'ats_score' => rand(50, 100),
        ]);
    }

    $admin = User::create([
        'name'     => 'Admin User',
        'email'    => 'admin@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['admin'],
    ]);
    $adminToken = auth()->login($admin);

    // Test pagination with different page sizes
    $pageSizes = [10, 25, 50, 100];
    foreach ($pageSizes as $size) {
        $res = $this->withHeader('Authorization', "Bearer $adminToken")
            ->getJson("/api/admin/users/seekers?per_page=$size")
            ->assertOk();
        
        expect($res->json('total'))->toBe(100);
        expect($res->json('per_page'))->toBe($size);
        expect(count($res->json('data')))->toBeLessThanOrEqual($size);
    }
});

test('profile viewing respects data privacy across roles', function () {
    // Create seeker with sensitive data
    $seeker = User::create([
        'name'     => 'Private Seeker',
        'email'    => 'private@test.com',
        'password' => Hash::make('password123'),
        'roles'    => ['employee'],
    ]);
    JobSeekerProfile::where('user_id', (string) $seeker->_id)->update([
        'ai_email'   => 'private@test.com',
        'ai_phone'   => '1234567890',
        'ai_summary' => 'Confidential summary',
    ]);

    // Create employer
    $emp = User::create([
        'name'     => 'Employer',
        'email'    => 'emp@test.com',
        'password' => Hash::make('pass'),
        'roles'    => ['employer'],
    ]);
    $empToken = auth()->login($emp);

    // Employer views seeker - should see all profile data
    $res = $this->withHeader('Authorization', "Bearer $empToken")
        ->getJson("/api/users/{$seeker->_id}")
        ->assertOk();

    // Verify profile data is present
    expect($res->json('user.profile.ai_email'))->toBe('private@test.com');
    expect($res->json('user.profile.ai_phone'))->toBe('1234567890');
    
    // But password should never be exposed
    expect($res->json('user'))->not->toHaveKey('password');
});
