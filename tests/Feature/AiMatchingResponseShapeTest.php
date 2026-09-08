<?php

// =============================================================================
// AI Matching Response Shape Tests
//
// Covers:
//   A. POST /api/employer/match-candidates
//      — enriches AI candidates with full JobSeekerProfile shape
//      — drops candidates not found in local DB
//      — returns correct top-level keys
//   B. POST /api/employer/jobs/{id}/match-candidates
//      — same enrichment via job post description
//      — 403 if employer doesn't own the post
//      — 404 if post not found
//   C. GET /api/job-seeker/match-resume-to-jobs
//      — enriches AI jobs with full JobPost shape + company snippet + has_applied
//      — drops jobs not found in local DB
//      — 422 if no CV on profile
// =============================================================================

use App\Models\Application;
use App\Models\CompanyProfile;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Http;

afterEach(function () {
    JobSeekerProfile::truncate();
    JobPost::truncate();
    CompanyProfile::truncate();
    Application::truncate();
});

// ── Helpers ───────────────────────────────────────────────────────────────────

function makeSeeker(array $profileExtra = []): array
{
    $user = User::factory()->employee()->create();
    $profile = JobSeekerProfile::create(array_merge([
        'user_id'          => (string) $user->_id,
        'full_name'        => $user->name,
        'current_job_title'=> 'Backend Developer',
        'city'             => 'Damascus',
        'ats_score'        => 75,
        'ai_skills'        => ['php', 'laravel'],
        'ai_summary'       => 'Experienced developer',
        'is_actively_seeking' => true,
        'cv_file_path'     => 'https://res.cloudinary.com/test/test.pdf',
    ], $profileExtra));
    return [$user, $profile];
}

function makeEmployerWithJob(): array
{
    $employer = User::factory()->employer()->create();
    $post = JobPost::create([
        'job_id'      => 'JOB-TEST-001',
        'employer_id' => (string) $employer->_id,
        'title'       => 'Senior Laravel Developer',
        'description' => 'Build APIs with Laravel and MongoDB.',
        'city'        => 'Damascus',
        'job_type'    => 'full_time',
        'is_active'   => true,
    ]);
    return [$employer, $post];
}

function fakeCandidatesResponse(array $userIds): void
{
    $candidates = collect($userIds)->map(fn ($id) => [
        'resume_id'            => $id,
        'name'                 => 'Test User',
        'matched_skills_score' => 3,
        'matched_skills'       => ['php', 'laravel'],
    ])->toArray();

    Http::fake([
        '*match-job-to-candidates*' => Http::response([
            'status'           => 'success',
            'skills_extracted' => ['php', 'laravel'],
            'matches_found'    => count($candidates),
            'candidates'       => $candidates,
        ], 200),
    ]);
}

function fakeJobsResponse(array $jobIds): void
{
    $jobs = collect($jobIds)->map(fn ($id) => [
        'job_id'               => $id,
        'title'                => 'Test Job',
        'company'              => 'Test Corp',
        'matched_skills_score' => 2,
        'matched_skills'       => ['php'],
    ])->toArray();

    Http::fake([
        '*match-candidate-to-jobs*' => Http::response([
            'status'        => 'success',
            'resume_id'     => 'test',
            'matches_found' => count($jobs),
            'jobs'          => $jobs,
        ], 200),
    ]);
}

// =============================================================================
// A. POST /api/employer/match-candidates
// =============================================================================

test('match-candidates returns enriched candidate profile shape', function () {
    [$seeker, $profile] = makeSeeker();
    [$employer]         = makeEmployerWithJob();
    $token              = auth('api')->login($employer);

    fakeCandidatesResponse([(string) $seeker->_id]);

    $res = $this->withToken($token)
        ->postJson('/api/employer/match-candidates', [
            'job_description' => 'Senior Laravel developer with MongoDB experience',
        ])
        ->assertStatus(200);

    $res->assertJsonStructure([
        'extracted_requirements',
        'candidates' => [[
            'id', 'user_id', 'name', 'email', 'image',
            'current_job_title', 'city', 'ats_score',
            'ai_skills', 'ai_summary', 'is_actively_seeking',
            'matched_skills_score', 'matched_skills', 'profile_url',
        ]],
    ]);

    $candidate = $res->json('candidates.0');
    expect($candidate['user_id'])->toBe((string) $seeker->_id);
    expect($candidate['name'])->toBe($seeker->name);
    expect($candidate['email'])->toBe($seeker->email);
    expect($candidate['ats_score'])->toBe(75);
    expect($candidate['matched_skills'])->toBe(['php', 'laravel']);
    expect($candidate['matched_skills_score'])->toBe(3);
    expect($candidate['profile_url'])->toBe("/api/employer/seekers/{$seeker->_id}");

    $seeker->delete();
    $employer->delete();
});

test('match-candidates drops candidates not found in local DB', function () {
    [$employer] = makeEmployerWithJob();
    $token      = auth('api')->login($employer);

    // AI returns an ID that doesn't exist in our DB
    fakeCandidatesResponse(['nonexistent_user_id_12345']);

    $res = $this->withToken($token)
        ->postJson('/api/employer/match-candidates', [
            'job_description' => 'PHP developer',
        ])
        ->assertStatus(200);

    expect($res->json('candidates'))->toBeEmpty();

    $employer->delete();
});

test('match-candidates returns 422 when job_description is missing', function () {
    [$employer] = makeEmployerWithJob();
    $token      = auth('api')->login($employer);

    $this->withToken($token)
        ->postJson('/api/employer/match-candidates', [])
        ->assertStatus(422);

    $employer->delete();
});

test('match-candidates requires employer role', function () {
    [$seeker] = makeSeeker();
    $token    = auth('api')->login($seeker);

    $this->withToken($token)
        ->postJson('/api/employer/match-candidates', ['job_description' => 'test'])
        ->assertStatus(403);

    $seeker->delete();
});

// =============================================================================
// B. POST /api/employer/jobs/{id}/match-candidates
// =============================================================================

test('match-candidates-to-job-post returns enriched shape', function () {
    [$seeker]        = makeSeeker();
    [$employer, $post] = makeEmployerWithJob();
    $token           = auth('api')->login($employer);

    fakeCandidatesResponse([(string) $seeker->_id]);

    $res = $this->withToken($token)
        ->postJson("/api/employer/jobs/{$post->_id}/match-candidates")
        ->assertStatus(200);

    $res->assertJsonStructure([
        'job_post' => ['id', 'title'],
        'extracted_requirements',
        'candidates' => [[
            'id', 'user_id', 'name', 'ats_score',
            'matched_skills_score', 'matched_skills', 'profile_url',
        ]],
    ]);

    expect($res->json('job_post.id'))->toBe((string) $post->_id);
    expect($res->json('job_post.title'))->toBe('Senior Laravel Developer');

    $seeker->delete();
    $employer->delete();
});

test('match-candidates-to-job-post returns 403 when employer does not own post', function () {
    [$seeker]        = makeSeeker();
    [$employer, $post] = makeEmployerWithJob();
    $otherEmployer   = User::factory()->employer()->create();
    $token           = auth('api')->login($otherEmployer);

    $this->withToken($token)
        ->postJson("/api/employer/jobs/{$post->_id}/match-candidates")
        ->assertStatus(403);

    $seeker->delete();
    $employer->delete();
    $otherEmployer->delete();
});

test('match-candidates-to-job-post returns 404 for nonexistent post', function () {
    [$employer] = makeEmployerWithJob();
    $token      = auth('api')->login($employer);

    $this->withToken($token)
        ->postJson('/api/employer/jobs/000000000000000000000000/match-candidates')
        ->assertStatus(404);

    $employer->delete();
});

// =============================================================================
// C. GET /api/job-seeker/match-resume-to-jobs
// =============================================================================

test('match-resume-to-jobs returns full job post shape', function () {
    [$seeker, $profile] = makeSeeker();
    $token              = auth('api')->login($seeker);

    // Create a company and a job post that the AI will return
    $company = CompanyProfile::create([
        'name'        => 'Acme Corp',
        'slug'        => 'acme-corp',
        'logo'        => null,
        'description' => 'A test company',
        'city'        => 'Damascus',
        'country'     => 'Syria',
    ]);

    $post = JobPost::create([
        'job_id'             => 'JOB-TEST-001',
        'employer_id'        => 'emp123',
        'company_profile_id' => (string) $company->_id,
        'company_name'       => 'Acme Corp',
        'title'              => 'Backend Engineer',
        'city'               => 'Damascus',
        'job_type'           => 'full_time',
        'is_active'          => true,
    ]);

    fakeJobsResponse(['JOB-TEST-001']);

    $res = $this->withToken($token)
        ->getJson('/api/job-seeker/match-resume-to-jobs')
        ->assertStatus(200);

    $res->assertJsonStructure([
        'matches_found',
        'jobs' => [[
            'job_id', 'title', 'city', 'job_type', 'is_active',
            'has_applied', 'matched_skills', 'matched_skills_score',
        ]],
    ]);

    $job = $res->json('jobs.0');
    expect($job['job_id'])->toBe('JOB-TEST-001');
    expect($job['title'])->toBe('Backend Engineer');
    expect($job['has_applied'])->toBeFalse();
    expect($job['matched_skills'])->toBe(['php']);
    expect($job['matched_skills_score'])->toBe(2);
    expect($job['company']['name'])->toBe('Acme Corp');

    $seeker->delete();
    $post->delete();
    $company->delete();
});

test('match-resume-to-jobs sets has_applied true when seeker already applied', function () {
    [$seeker, $profile] = makeSeeker();
    $token              = auth('api')->login($seeker);

    $post = JobPost::create([
        'job_id'      => 'JOB-TEST-002',
        'employer_id' => 'emp123',
        'title'       => 'PHP Developer',
        'is_active'   => true,
    ]);

    Application::create([
        'user_id'     => (string) $seeker->_id,
        'job_post_id' => (string) $post->_id,
        'status'      => 'pending',
        'applied_at'  => now(),
    ]);

    fakeJobsResponse(['JOB-TEST-002']);

    $res = $this->withToken($token)
        ->getJson('/api/job-seeker/match-resume-to-jobs')
        ->assertStatus(200);

    expect($res->json('jobs.0.has_applied'))->toBeTrue();

    $seeker->delete();
    $post->delete();
});

test('match-resume-to-jobs drops jobs not found in local DB', function () {
    [$seeker] = makeSeeker();
    $token    = auth('api')->login($seeker);

    fakeJobsResponse(['JOB-DOES-NOT-EXIST-999']);

    $res = $this->withToken($token)
        ->getJson('/api/job-seeker/match-resume-to-jobs')
        ->assertStatus(200);

    expect($res->json('jobs'))->toBeEmpty();
    expect($res->json('matches_found'))->toBe(0);

    $seeker->delete();
});

test('match-resume-to-jobs returns 422 when seeker has no CV', function () {
    $user = User::factory()->employee()->create();
    JobSeekerProfile::create([
        'user_id'      => (string) $user->_id,
        'cv_file_path' => null,
    ]);
    $token = auth('api')->login($user);

    $this->withToken($token)
        ->getJson('/api/job-seeker/match-resume-to-jobs')
        ->assertStatus(422)
        ->assertJsonPath('message', 'No CV found on your profile. Please upload and analyze your CV first.');

    $user->delete();
});

test('match-resume-to-jobs requires authentication', function () {
    $this->getJson('/api/job-seeker/match-resume-to-jobs')->assertStatus(401);
});
