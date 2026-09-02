<?php

// Covers the public job search endpoint (GET /api/jobs/search, handled by
// JobSeekerController@searchJobs): filter params (keyword, location→city,
// job_type, category, min_salary→salary_from), inactive-job exclusion, the
// {jobs} paginated shape, validation, and public accessibility.

// A searchable job carrying the fields the search controller filters on, on top
// of the active-job defaults.
function searchableJob(\App\Models\User $employer, array $overrides = []): \App\Models\JobPost
{
    return createJob($employer, array_merge([
        'title'        => 'Seeker Search Job',
        'company_name' => 'SearchCo',
        'category'     => 'Engineering',
        'salary_from'  => 2000,
        'salary_to'    => 5000,
    ], $overrides));
}

beforeEach(function () {
    $this->employer = createUser('employer');
});

// ── GET /api/jobs/search ───────────────────────────────────────────

test('anyone can search active jobs', function () {
    searchableJob($this->employer, ['title' => 'Active Search Job']);

    $this->getJson('/api/jobs/search')
        ->assertOk()
        ->assertJsonStructure(['jobs']);
});

test('search returns paginated jobs shape', function () {
    $this->getJson('/api/jobs/search')
        ->assertOk()
        ->assertJsonStructure(['jobs' => ['data', 'current_page', 'per_page', 'total']]);
});

test('search filters by keyword in title', function () {
    searchableJob($this->employer, ['title' => 'UniqueSeekTitle_XYZ']);

    $response = $this->getJson('/api/jobs/search?keyword=UniqueSeekTitle_XYZ')->assertOk();

    $titles = collect($response->json('jobs.data'))->pluck('title')->toArray();
    expect($titles)->toContain('UniqueSeekTitle_XYZ');
});

test('search filters by keyword in description', function () {
    searchableJob($this->employer, ['title' => 'Some Job', 'description' => 'UNIQUE_DESC_TERM_ABC']);

    $response = $this->getJson('/api/jobs/search?keyword=UNIQUE_DESC_TERM_ABC')->assertOk();

    $descriptions = collect($response->json('jobs.data'))->pluck('description')->toArray();
    expect(implode(' ', $descriptions))->toContain('UNIQUE_DESC_TERM_ABC');
});

test('search filters by location', function () {
    searchableJob($this->employer, ['title' => 'Dubai Job', 'city' => 'Dubai']);

    $response = $this->getJson('/api/jobs/search?location=Dubai')->assertOk();

    foreach ($response->json('jobs.data') as $j) {
        expect(strtolower($j['city']))->toContain('dubai');
    }
});

test('search filters by job_type', function () {
    searchableJob($this->employer, ['title' => 'Part Time Job', 'job_type' => 'part_time']);

    $response = $this->getJson('/api/jobs/search?job_type=part_time')->assertOk();

    foreach ($response->json('jobs.data') as $j) {
        expect($j['job_type'])->toBe('part_time');
    }
});

test('search filters by category', function () {
    searchableJob($this->employer, ['title' => 'Design Job', 'category' => 'Design']);

    $response = $this->getJson('/api/jobs/search?category=Design')->assertOk();

    foreach ($response->json('jobs.data') as $j) {
        expect($j['category'])->toBe('Design');
    }
});

test('search filters by min_salary', function () {
    searchableJob($this->employer, ['title' => 'High Pay Job', 'salary_from' => 6000, 'salary_to' => 10000]);

    $response = $this->getJson('/api/jobs/search?min_salary=6000')->assertOk();

    foreach ($response->json('jobs.data') as $j) {
        expect($j['salary_from'])->toBeGreaterThanOrEqual(6000);
    }
});

test('search excludes inactive jobs', function () {
    $job = searchableJob($this->employer, ['title' => 'Inactive Seeker Job', 'is_active' => false]);

    $response = $this->getJson('/api/jobs/search?keyword=Inactive+Seeker+Job')->assertOk();

    $ids = collect($response->json('jobs.data'))->pluck('_id')->toArray();
    expect($ids)->not->toContain((string) $job->_id);
});

test('search rejects invalid job_type', function () {
    $this->getJson('/api/jobs/search?job_type=gig_economy')->assertStatus(422);
});

// ── Public accessibility ───────────────────────────────────────────
// This endpoint is public: it requires no authentication and no particular
// role, so both an unauthenticated visitor and an employer can reach it.

test('search is reachable without authentication', function () {
    $this->getJson('/api/jobs/search')->assertOk();
});

test('search is reachable by an employer', function () {
    $this->withToken(tokenFor('employer'))
        ->getJson('/api/jobs/search')
        ->assertOk();
});
