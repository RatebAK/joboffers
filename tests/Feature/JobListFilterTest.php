<?php

// Covers the public job listing (GET /api/jobs) and company listing
// (GET /api/companies): pagination shape and every supported filter.

use App\Models\CompanyProfile;

// Default extra fields the filter tests rely on, layered on top of createJob().
function filterJob(\App\Models\User $employer, array $overrides = []): \App\Models\JobPost
{
    return createJob($employer, array_merge([
        'company_name'     => 'Acme Corp',
        'job_level'        => 'mid',
        'experience_years' => 3,
        'category'         => 'Engineering',
        'tags'             => ['PHP', 'Laravel'],
        'salary_from'      => 2000,
        'salary_to'        => 4000,
        'currency'         => 'USD',
        'title'            => 'Software Engineer',
    ], $overrides));
}

function filterCompany(\App\Models\User $employer, array $overrides = []): CompanyProfile
{
    return CompanyProfile::updateOrCreate(
        ['employer_id' => (string) $employer->_id],
        array_merge([
            'name'         => 'Acme Corp',
            'slug'         => 'acme-'.uniqid(),
            'logo'         => 'https://example.com/logo.png',
            'description'  => 'A great tech company.',
            'city'         => 'Beirut',
            'country'      => 'Lebanon',
            'company_size' => '10_to_50',
            'industry'     => 'Technology',
            'rating'       => 4.5,
            'review_count' => 120,
        ], $overrides)
    );
}

beforeEach(function () {
    $this->employer = createUser('employer');
});

// ── Pagination shape — jobs ────────────────────────────────────────

test('job list returns correct pagination keys', function () {
    filterJob($this->employer);

    $response = $this->getJson('/api/jobs')->assertOk()
        ->assertJsonStructure([
            'data', 'current_page', 'per_page', 'total', 'total_pages', 'next_page', 'prev_page',
        ]);

    expect($response->json('current_page'))->toBe(1);
    expect($response->json('prev_page'))->toBeNull();
});

test('job list respects per_page parameter', function () {
    collect(range(1, 5))->each(fn ($i) => filterJob($this->employer, ['title' => "Job $i"]));

    $response = $this->getJson('/api/jobs?per_page=2')->assertOk();

    expect(count($response->json('data')))->toBeLessThanOrEqual(2);
    expect($response->json('per_page'))->toBe(2);
    expect($response->json('total_pages'))->toBeGreaterThanOrEqual(1);
});

test('job list page 2 has correct prev_page and next_page', function () {
    collect(range(1, 4))->each(fn ($i) => filterJob($this->employer, ['title' => "Paged Job $i"]));

    $response = $this->getJson('/api/jobs?per_page=2&page=2')->assertOk();

    expect($response->json('current_page'))->toBe(2);
    expect($response->json('prev_page'))->toBe(1);
});

// ── Job filters ────────────────────────────────────────────────────

test('filter jobs by keyword matches title', function () {
    filterJob($this->employer, ['title' => 'UniqueReactDeveloper']);

    $response = $this->getJson('/api/jobs?keyword=UniqueReactDeveloper')->assertOk();

    $titles = collect($response->json('data'))->pluck('title')->toArray();
    expect($titles)->toContain('UniqueReactDeveloper');
});

test('filter jobs by keyword matches description', function () {
    filterJob($this->employer, ['description' => 'XYZ_UNIQUE_KEYWORD_DESC']);

    $response = $this->getJson('/api/jobs?keyword=XYZ_UNIQUE_KEYWORD_DESC')->assertOk();

    $descriptions = collect($response->json('data'))->pluck('description')->toArray();
    expect(implode(' ', $descriptions))->toContain('XYZ_UNIQUE_KEYWORD_DESC');
});

test('filter jobs by location', function () {
    filterJob($this->employer, ['city' => 'Dubai']);

    $response = $this->getJson('/api/jobs?city=Dubai')->assertOk();

    foreach ($response->json('data') as $j) {
        expect(strtolower($j['city']))->toContain('dubai');
    }
});

test('filter jobs by job_type', function () {
    filterJob($this->employer, ['job_type' => 'part_time']);

    $response = $this->getJson('/api/jobs?job_type=part_time')->assertOk();

    foreach ($response->json('data') as $j) {
        expect($j['job_type'])->toBe('part_time');
    }
});

test('filter jobs by work_mode', function () {
    filterJob($this->employer, ['work_mode' => 'hybrid']);

    $response = $this->getJson('/api/jobs?work_mode=hybrid')->assertOk();

    foreach ($response->json('data') as $j) {
        expect($j['work_mode'])->toBe('hybrid');
    }
});

test('filter jobs by experience_level', function () {
    filterJob($this->employer, ['job_level' => 'senior']);

    $response = $this->getJson('/api/jobs?job_level=senior')->assertOk();

    foreach ($response->json('data') as $j) {
        expect($j['job_level'])->toBe('senior');
    }
});

test('filter jobs by category', function () {
    filterJob($this->employer, ['category' => 'Design']);

    $response = $this->getJson('/api/jobs?category=Design')->assertOk();

    foreach ($response->json('data') as $j) {
        expect($j['category'])->toBe('Design');
    }
});

test('filter jobs by tag', function () {
    filterJob($this->employer, ['tags' => ['Vue.js', 'TypeScript']]);

    $response = $this->getJson('/api/jobs?tag=Vue.js')->assertOk();

    foreach ($response->json('data') as $j) {
        expect($j['tags'])->toContain('Vue.js');
    }
});

test('filter jobs by min_salary', function () {
    filterJob($this->employer, ['salary_range' => ['min' => 5000, 'max' => 8000, 'currency' => 'USD']]);

    $response = $this->getJson('/api/jobs?min_salary=5000')->assertOk();

    foreach ($response->json('data') as $j) {
        expect($j['salary_range']['min'])->toBeGreaterThanOrEqual(5000);
    }
});

test('filter jobs by max_salary', function () {
    filterJob($this->employer, ['salary_range' => ['min' => 1000, 'max' => 2500, 'currency' => 'USD']]);

    $response = $this->getJson('/api/jobs?max_salary=3000')->assertOk();

    foreach ($response->json('data') as $j) {
        expect($j['salary_range']['max'])->toBeLessThanOrEqual(3000);
    }
});

test('inactive jobs are excluded from public listing', function () {
    $job = filterJob($this->employer, ['is_active' => false]);

    $response = $this->getJson('/api/jobs')->assertOk();

    $ids = collect($response->json('data'))->pluck('_id')->toArray();
    expect($ids)->not->toContain((string) $job->_id);
});

// ── Pagination shape — companies ───────────────────────────────────

test('company list returns correct pagination keys', function () {
    filterCompany($this->employer);

    $response = $this->getJson('/api/companies')->assertOk()
        ->assertJsonStructure([
            'data', 'current_page', 'per_page', 'total', 'total_pages', 'next_page', 'prev_page',
        ]);

    expect($response->json('current_page'))->toBe(1);
    expect($response->json('prev_page'))->toBeNull();
});

test('company list items include open_positions count', function () {
    filterCompany($this->employer);
    filterJob($this->employer);

    $response = $this->getJson('/api/companies')->assertOk();

    foreach ($response->json('data') as $c) {
        expect($c)->toHaveKey('open_positions');
    }
});

// ── Company filters ────────────────────────────────────────────────

test('filter companies by search name', function () {
    filterCompany($this->employer, ['name' => 'UniqueCompanyXYZ']);

    $response = $this->getJson('/api/companies?search=UniqueCompanyXYZ')->assertOk();

    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toContain('UniqueCompanyXYZ');
});

test('filter companies by search location', function () {
    filterCompany($this->employer, ['city' => 'Tripoli']);

    $response = $this->getJson('/api/companies?search=Tripoli')->assertOk();

    $found = collect($response->json('data'))->first(fn ($c) => str_contains($c['city'] ?? '', 'Tripoli'));
    expect($found)->not->toBeNull();
});

test('filter companies by industry', function () {
    filterCompany($this->employer, ['industry' => 'Healthcare']);

    $response = $this->getJson('/api/companies?industry=Healthcare')->assertOk();

    foreach ($response->json('data') as $c) {
        expect(strtolower($c['industry']))->toContain('healthcare');
    }
});

test('filter companies by min_rating', function () {
    filterCompany($this->employer, ['rating' => 4.8]);

    $response = $this->getJson('/api/companies?min_rating=4.5')->assertOk();

    foreach ($response->json('data') as $c) {
        expect($c['rating'])->toBeGreaterThanOrEqual(4.5);
    }
});

test('filter companies by company_size', function () {
    filterCompany($this->employer, ['company_size' => '501_to_1000']);

    $response = $this->getJson('/api/companies?company_size=501_to_1000')->assertOk();

    $found = collect($response->json('data'))->first(fn ($c) => ($c['company_size'] ?? '') === '501_to_1000');
    expect($found)->not->toBeNull();
});

test('company list respects per_page parameter', function () {
    collect(range(1, 4))->each(function ($i) {
        $emp = createUser('employer');
        filterCompany($emp, ['name' => "Company $i"]);
    });

    $response = $this->getJson('/api/companies?per_page=2')->assertOk();

    expect(count($response->json('data')))->toBeLessThanOrEqual(2);
    expect($response->json('per_page'))->toBe(2);
});
