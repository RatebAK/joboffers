<?php

// ============================================================
// DO NOT DELETE — Tests for the job seeker job search endpoint.
// Covers: authenticated access, all filter params (keyword,
// location, job_type, category, min_salary), inactive job
// exclusion, pagination shape, and auth/role guards.
// ============================================================

use App\Models\JobPost;
use App\Models\User;

// ── Helpers ───────────────────────────────────────────────────

function seekerSearchUser(): array
{
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);
    return [$seeker, $token];
}

function seekerJob(string $employerId, array $overrides = []): JobPost
{
    $employer = User::factory()->employer()->create(['_id' => $employerId]);
    return JobPost::create(array_merge([
        'title'        => 'Seeker Search Job',
        'description'  => 'A job for seekers.',
        'requirements' => 'PHP.',
        'company_name' => 'SearchCo',
        'job_type'     => 'full_time',
        'location'     => 'Beirut, Lebanon',
        'category'     => 'Engineering',
        'employer_id'  => $employerId,
        'is_active'    => true,
        'salary_range' => ['min' => 2000, 'max' => 5000, 'currency' => 'USD'],
    ], $overrides));
}

// ── GET /api/job-seeker/jobs/search ──────────────────────────

test('seeker can search active jobs', function () {
    [, $token] = seekerSearchUser();
    $employer  = User::factory()->employer()->create();
    $job = JobPost::create([
        'title'        => 'Active Search Job',
        'description'  => 'Desc.',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'full_time',
        'location'     => 'Remote',
        'employer_id'  => (string) $employer->_id,
        'is_active'    => true,
    ]);

    $this->withToken($token)->getJson('/api/job-seeker/jobs/search')
         ->assertStatus(200)
         ->assertJsonStructure(['jobs']);

    $job->delete(); $employer->delete();
});

test('seeker search returns paginated jobs shape', function () {
    [, $token] = seekerSearchUser();

    $this->withToken($token)->getJson('/api/job-seeker/jobs/search')
         ->assertStatus(200)
         ->assertJsonStructure(['jobs' => ['data', 'current_page', 'per_page', 'total']]);
});

test('seeker search filters by keyword in title', function () {
    [, $token] = seekerSearchUser();
    $employer  = User::factory()->employer()->create();
    $job = JobPost::create([
        'title'        => 'UniqueSeekTitle_XYZ',
        'description'  => 'Desc.',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'full_time',
        'location'     => 'Remote',
        'employer_id'  => (string) $employer->_id,
        'is_active'    => true,
    ]);

    $response = $this->withToken($token)->getJson('/api/job-seeker/jobs/search?keyword=UniqueSeekTitle_XYZ')
         ->assertStatus(200);

    $titles = collect($response->json('jobs.data'))->pluck('title')->toArray();
    expect($titles)->toContain('UniqueSeekTitle_XYZ');

    $job->delete(); $employer->delete();
});

test('seeker search filters by keyword in description', function () {
    [, $token] = seekerSearchUser();
    $employer  = User::factory()->employer()->create();
    $job = JobPost::create([
        'title'        => 'Some Job',
        'description'  => 'UNIQUE_DESC_TERM_ABC',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'full_time',
        'location'     => 'Remote',
        'employer_id'  => (string) $employer->_id,
        'is_active'    => true,
    ]);

    $response = $this->withToken($token)->getJson('/api/job-seeker/jobs/search?keyword=UNIQUE_DESC_TERM_ABC')
         ->assertStatus(200);

    $descriptions = collect($response->json('jobs.data'))->pluck('description')->toArray();
    expect(implode(' ', $descriptions))->toContain('UNIQUE_DESC_TERM_ABC');

    $job->delete(); $employer->delete();
});

test('seeker search filters by location', function () {
    [, $token] = seekerSearchUser();
    $employer  = User::factory()->employer()->create();
    $job = JobPost::create([
        'title'        => 'Dubai Job',
        'description'  => 'Desc.',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'full_time',
        'location'     => 'Dubai, UAE',
        'employer_id'  => (string) $employer->_id,
        'is_active'    => true,
    ]);

    $response = $this->withToken($token)->getJson('/api/job-seeker/jobs/search?location=Dubai')
         ->assertStatus(200);

    foreach ($response->json('jobs.data') as $j) {
        expect(strtolower($j['location']))->toContain('dubai');
    }

    $job->delete(); $employer->delete();
});

test('seeker search filters by job_type', function () {
    [, $token] = seekerSearchUser();
    $employer  = User::factory()->employer()->create();
    $job = JobPost::create([
        'title'        => 'Part Time Job',
        'description'  => 'Desc.',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'part_time',
        'location'     => 'Remote',
        'employer_id'  => (string) $employer->_id,
        'is_active'    => true,
    ]);

    $response = $this->withToken($token)->getJson('/api/job-seeker/jobs/search?job_type=part_time')
         ->assertStatus(200);

    foreach ($response->json('jobs.data') as $j) {
        expect($j['job_type'])->toBe('part_time');
    }

    $job->delete(); $employer->delete();
});

test('seeker search filters by category', function () {
    [, $token] = seekerSearchUser();
    $employer  = User::factory()->employer()->create();
    $job = JobPost::create([
        'title'        => 'Design Job',
        'description'  => 'Desc.',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'full_time',
        'location'     => 'Remote',
        'category'     => 'Design',
        'employer_id'  => (string) $employer->_id,
        'is_active'    => true,
    ]);

    $response = $this->withToken($token)->getJson('/api/job-seeker/jobs/search?category=Design')
         ->assertStatus(200);

    foreach ($response->json('jobs.data') as $j) {
        expect($j['category'])->toBe('Design');
    }

    $job->delete(); $employer->delete();
});

test('seeker search filters by min_salary', function () {
    [, $token] = seekerSearchUser();
    $employer  = User::factory()->employer()->create();
    $job = JobPost::create([
        'title'        => 'High Pay Job',
        'description'  => 'Desc.',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'full_time',
        'location'     => 'Remote',
        'employer_id'  => (string) $employer->_id,
        'is_active'    => true,
        'salary_range' => ['min' => 6000, 'max' => 10000, 'currency' => 'USD'],
    ]);

    $response = $this->withToken($token)->getJson('/api/job-seeker/jobs/search?min_salary=6000')
         ->assertStatus(200);

    foreach ($response->json('jobs.data') as $j) {
        expect($j['salary_range']['min'])->toBeGreaterThanOrEqual(6000);
    }

    $job->delete(); $employer->delete();
});

test('seeker search excludes inactive jobs', function () {
    [, $token] = seekerSearchUser();
    $employer  = User::factory()->employer()->create();
    $job = JobPost::create([
        'title'        => 'Inactive Seeker Job',
        'description'  => 'Desc.',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'full_time',
        'location'     => 'Remote',
        'employer_id'  => (string) $employer->_id,
        'is_active'    => false,
    ]);

    $response = $this->withToken($token)->getJson('/api/job-seeker/jobs/search?keyword=Inactive+Seeker+Job')
         ->assertStatus(200);

    $ids = collect($response->json('jobs.data'))->pluck('_id')->toArray();
    expect($ids)->not->toContain((string) $job->_id);

    $job->delete(); $employer->delete();
});

test('seeker search rejects invalid job_type', function () {
    [, $token] = seekerSearchUser();

    $this->withToken($token)->getJson('/api/job-seeker/jobs/search?job_type=gig_economy')
         ->assertStatus(422);
});

test('unauthenticated user cannot search jobs via seeker endpoint', function () {
    $this->getJson('/api/job-seeker/jobs/search')->assertStatus(401);
});

test('employer cannot access seeker job search endpoint', function () {
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);

    $this->withToken($token)->getJson('/api/job-seeker/jobs/search')->assertStatus(403);

    $employer->delete();
});
