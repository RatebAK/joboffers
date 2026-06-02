<?php

use App\Models\Application;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    User::truncate();
    JobSeekerProfile::truncate();
    Employer::truncate();
    JobPost::truncate();
    Application::truncate();
});

test('matched jobs returns posts with higher scores for matching skills', function () {
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker',
        'email'    => 'seeker@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerToken = $seekerRes->json('token');
    $seekerId    = $seekerRes->json('user.id');

    Http::fake([
        '*' => Http::response([
            'status'   => 'success',
            'analysis' => [
                'full_name'          => 'Test Seeker',
                'email'              => 'seeker@test.com',
                'phone'              => '123456789',
                'location'           => 'Beirut',
                'summary'            => 'Experienced developer',
                'skills'             => ['PHP', 'Laravel', 'JavaScript'],
                'work_history'       => [],
                'projects'           => [],
                'overall_evaluation' => 'Strong candidate',
                'ats_score'          => 85,
                'detected_language'  => 'en',
            ],
        ], 200),
    ]);

    $this->withHeader('Authorization', "Bearer $seekerToken")
        ->post('/api/job-seeker/resume/upload-and-analyze', [
            'cv_file' => \Illuminate\Http\UploadedFile::fake()->create('resume.pdf', 100),
        ])
        ->assertOk();

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

    $this->withHeader('Authorization', "Bearer $empToken")
        ->postJson('/api/jobs', [
            'title'       => 'PHP Developer',
            'description' => 'Desc',
            'company_name'=> 'Company',
            'roles'       => ['Backend', 'PHP'],
            'tags'        => ['Laravel'],
        ])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer $empToken")
        ->postJson('/api/jobs', [
            'title'       => 'iOS Developer',
            'description' => 'Desc',
            'company_name'=> 'Company',
            'roles'       => ['Mobile', 'iOS'],
            'tags'        => ['Swift'],
        ])
        ->assertCreated();

    $res = $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson('/api/job-seeker/matched-jobs')
        ->assertOk();

    $jobs = $res->json('data');
    expect(count($jobs))->toBe(2);
    expect($jobs[0]['title'])->toBe('PHP Developer');
    expect($jobs[0]['match_score'])->toBe(4);
    expect($jobs[1]['title'])->toBe('iOS Developer');
    expect($jobs[1]['match_score'])->toBe(0);
});

test('matched jobs applies location bonus when location matches', function () {
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker',
        'email'    => 'seeker@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerToken = $seekerRes->json('token');
    $seekerId    = $seekerRes->json('user.id');

    JobSeekerProfile::where('user_id', $seekerId)->update([
        'ai_location' => 'Beirut, Lebanon',
        'ai_skills'   => ['PHP'],
    ]);

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

    $this->withHeader('Authorization', "Bearer $empToken")
        ->postJson('/api/jobs', [
            'title'       => 'Job in Beirut',
            'description' => 'Desc',
            'company_name'=> 'Company',
            'location'    => 'Beirut',
        ])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer $empToken")
        ->postJson('/api/jobs', [
            'title'       => 'Job in Dubai',
            'description' => 'Desc',
            'company_name'=> 'Company',
            'location'    => 'Dubai',
        ])
        ->assertCreated();

    $res = $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson('/api/job-seeker/matched-jobs')
        ->assertOk();

    $jobs = $res->json('data');
    $beirutJob = collect($jobs)->firstWhere('title', 'Job in Beirut');
    expect($beirutJob['match_score'])->toBe(3);

    $dubaiJob = collect($jobs)->firstWhere('title', 'Job in Dubai');
    expect($dubaiJob['match_score'])->toBe(0);
});

test('matched jobs excludes posts seeker has already applied to', function () {
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker',
        'email'    => 'seeker@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerToken = $seekerRes->json('token');

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

    $job1Res = $this->withHeader('Authorization', "Bearer $empToken")
        ->postJson('/api/jobs', [
            'title'       => 'Job 1',
            'description' => 'Desc',
            'company_name'=> 'Company',
        ])
        ->assertCreated();
    $job1Id = $job1Res->json('job.id');

    $this->withHeader('Authorization', "Bearer $empToken")
        ->postJson('/api/jobs', [
            'title'       => 'Job 2',
            'description' => 'Desc',
            'company_name'=> 'Company',
        ])
        ->assertCreated();

    $this->withHeader('Authorization', "Bearer $seekerToken")
        ->postJson("/api/jobs/{$job1Id}/apply")
        ->assertCreated();

    $res = $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson('/api/job-seeker/matched-jobs')
        ->assertOk();

    $jobs = $res->json('data');
    expect(count($jobs))->toBe(1);
    expect($jobs[0]['title'])->toBe('Job 2');
});

test('seeker with no profile gets active jobs with match_score zero', function () {
    $seekerRes = $this->postJson('/api/auth/register', [
        'name'     => 'Seeker',
        'email'    => 'seeker@test.com',
        'password' => 'password123',
        'roles'    => ['employee'],
    ]);
    $seekerToken = $seekerRes->json('token');
    $seekerId    = $seekerRes->json('user.id');

    JobSeekerProfile::where('user_id', $seekerId)->delete();

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

    $this->withHeader('Authorization', "Bearer $empToken")
        ->postJson('/api/jobs', [
            'title'       => 'Active Job',
            'description' => 'Desc',
            'company_name'=> 'Company',
        ])
        ->assertCreated();

    $res = $this->withHeader('Authorization', "Bearer $seekerToken")
        ->getJson('/api/job-seeker/matched-jobs')
        ->assertOk();

    $jobs = $res->json('data');
    expect(count($jobs))->toBe(1);
    expect($jobs[0]['match_score'])->toBe(0);
});
