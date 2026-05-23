<?php

// ============================================================
// DO NOT DELETE — Tests for job listing filters, company listing
// filters, and pagination shape for both endpoints.
// ============================================================

use App\Models\CompanyProfile;
use App\Models\JobPost;
use App\Models\User;

// ── Helpers ───────────────────────────────────────────────────

function makeFilterEmployer(): User
{
    return User::factory()->employer()->create();
}

function makeJob(string $employerId, array $overrides = []): JobPost
{
    return JobPost::create(array_merge([
        'title'               => 'Software Engineer',
        'description'         => 'Build great software.',
        'requirements'        => 'PHP experience.',
        'company_name'        => 'Acme Corp',
        'job_type'            => 'full_time',
        'work_mode'           => 'remote',
        'experience_level'    => 'mid',
        'experience_required' => '3+ years',
        'location'            => 'Beirut, Lebanon',
        'category'            => 'Engineering',
        'tags'                => ['PHP', 'Laravel'],
        'salary_range'        => ['min' => 2000, 'max' => 4000, 'currency' => 'USD'],
        'employer_id'         => $employerId,
        'is_active'           => true,
    ], $overrides));
}

function makeCompany(string $employerId, array $overrides = []): CompanyProfile
{
    return CompanyProfile::updateOrCreate(
        ['employer_id' => $employerId],
        array_merge([
            'name'         => 'Acme Corp',
            'logo'         => 'https://example.com/logo.png',
            'description'  => 'A great tech company.',
            'location'     => 'Beirut, Lebanon',
            'company_size' => '100-500 employees',
            'industry'     => 'Technology',
            'website'      => 'https://acme.com',
            'rating'       => 4.5,
            'review_count' => 120,
        ], $overrides)
    );
}

// ── Pagination Shape — Jobs ───────────────────────────────────

test('job list returns correct pagination keys', function () {
    $employer = makeFilterEmployer();
    $job = makeJob((string) $employer->_id);

    $response = $this->getJson('/api/jobs');

    $response->assertStatus(200)
             ->assertJsonStructure([
                 'data',
                 'current_page',
                 'per_page',
                 'total',
                 'total_pages',
                 'next_page',
                 'prev_page',
                 'next_page_url',
                 'prev_page_url',
             ]);

    expect($response->json('current_page'))->toBe(1);
    expect($response->json('prev_page'))->toBeNull();

    $job->delete();
    $employer->delete();
});

test('job list respects per_page parameter', function () {
    $employer = makeFilterEmployer();
    $jobs = collect(range(1, 5))->map(fn($i) => makeJob((string) $employer->_id, ['title' => "Job $i"]));

    $response = $this->getJson('/api/jobs?per_page=2');

    $response->assertStatus(200);
    expect(count($response->json('data')))->toBeLessThanOrEqual(2);
    expect($response->json('per_page'))->toBe(2);
    expect($response->json('total_pages'))->toBeGreaterThanOrEqual(1);

    $jobs->each->delete();
    $employer->delete();
});

test('job list page 2 has correct prev_page and next_page', function () {
    $employer = makeFilterEmployer();
    $jobs = collect(range(1, 4))->map(fn($i) => makeJob((string) $employer->_id, ['title' => "Paged Job $i"]));

    $response = $this->getJson('/api/jobs?per_page=2&page=2');

    $response->assertStatus(200);
    expect($response->json('current_page'))->toBe(2);
    expect($response->json('prev_page'))->toBe(1);

    $jobs->each->delete();
    $employer->delete();
});

// ── Job Filters ───────────────────────────────────────────────

test('filter jobs by keyword matches title', function () {
    $employer = makeFilterEmployer();
    $job = makeJob((string) $employer->_id, ['title' => 'UniqueReactDeveloper']);

    $response = $this->getJson('/api/jobs?keyword=UniqueReactDeveloper');

    $response->assertStatus(200);
    $titles = collect($response->json('data'))->pluck('title')->toArray();
    expect($titles)->toContain('UniqueReactDeveloper');

    $job->delete();
    $employer->delete();
});

test('filter jobs by keyword matches description', function () {
    $employer = makeFilterEmployer();
    $job = makeJob((string) $employer->_id, ['description' => 'XYZ_UNIQUE_KEYWORD_DESC']);

    $response = $this->getJson('/api/jobs?keyword=XYZ_UNIQUE_KEYWORD_DESC');

    $response->assertStatus(200);
    $descriptions = collect($response->json('data'))->pluck('description')->toArray();
    expect(implode(' ', $descriptions))->toContain('XYZ_UNIQUE_KEYWORD_DESC');

    $job->delete();
    $employer->delete();
});

test('filter jobs by location', function () {
    $employer = makeFilterEmployer();
    $job = makeJob((string) $employer->_id, ['location' => 'Dubai, UAE']);

    $response = $this->getJson('/api/jobs?location=Dubai');

    $response->assertStatus(200);
    foreach ($response->json('data') as $j) {
        expect(strtolower($j['location']))->toContain('dubai');
    }

    $job->delete();
    $employer->delete();
});

test('filter jobs by job_type', function () {
    $employer = makeFilterEmployer();
    $job = makeJob((string) $employer->_id, ['job_type' => 'part_time']);

    $response = $this->getJson('/api/jobs?job_type=part_time');

    $response->assertStatus(200);
    foreach ($response->json('data') as $j) {
        expect($j['job_type'])->toBe('part_time');
    }

    $job->delete();
    $employer->delete();
});

test('filter jobs by work_mode', function () {
    $employer = makeFilterEmployer();
    $job = makeJob((string) $employer->_id, ['work_mode' => 'hybrid']);

    $response = $this->getJson('/api/jobs?work_mode=hybrid');

    $response->assertStatus(200);
    foreach ($response->json('data') as $j) {
        expect($j['work_mode'])->toBe('hybrid');
    }

    $job->delete();
    $employer->delete();
});

test('filter jobs by experience_level', function () {
    $employer = makeFilterEmployer();
    $job = makeJob((string) $employer->_id, ['experience_level' => 'senior']);

    $response = $this->getJson('/api/jobs?experience_level=senior');

    $response->assertStatus(200);
    foreach ($response->json('data') as $j) {
        expect($j['experience_level'])->toBe('senior');
    }

    $job->delete();
    $employer->delete();
});

test('filter jobs by category', function () {
    $employer = makeFilterEmployer();
    $job = makeJob((string) $employer->_id, ['category' => 'Design']);

    $response = $this->getJson('/api/jobs?category=Design');

    $response->assertStatus(200);
    foreach ($response->json('data') as $j) {
        expect($j['category'])->toBe('Design');
    }

    $job->delete();
    $employer->delete();
});

test('filter jobs by tag', function () {
    $employer = makeFilterEmployer();
    $job = makeJob((string) $employer->_id, ['tags' => ['Vue.js', 'TypeScript']]);

    $response = $this->getJson('/api/jobs?tag=Vue.js');

    $response->assertStatus(200);
    foreach ($response->json('data') as $j) {
        expect($j['tags'])->toContain('Vue.js');
    }

    $job->delete();
    $employer->delete();
});

test('filter jobs by min_salary', function () {
    $employer = makeFilterEmployer();
    $job = makeJob((string) $employer->_id, ['salary_range' => ['min' => 5000, 'max' => 8000, 'currency' => 'USD']]);

    $response = $this->getJson('/api/jobs?min_salary=5000');

    $response->assertStatus(200);
    foreach ($response->json('data') as $j) {
        expect($j['salary_range']['min'])->toBeGreaterThanOrEqual(5000);
    }

    $job->delete();
    $employer->delete();
});

test('filter jobs by max_salary', function () {
    $employer = makeFilterEmployer();
    $job = makeJob((string) $employer->_id, ['salary_range' => ['min' => 1000, 'max' => 2500, 'currency' => 'USD']]);

    $response = $this->getJson('/api/jobs?max_salary=3000');

    $response->assertStatus(200);
    foreach ($response->json('data') as $j) {
        expect($j['salary_range']['max'])->toBeLessThanOrEqual(3000);
    }

    $job->delete();
    $employer->delete();
});

test('inactive jobs are excluded from public listing', function () {
    $employer = makeFilterEmployer();
    $job = makeJob((string) $employer->_id, ['is_active' => false]);

    $response = $this->getJson('/api/jobs');

    $ids = collect($response->json('data'))->pluck('_id')->toArray();
    expect($ids)->not->toContain((string) $job->_id);

    $job->delete();
    $employer->delete();
});

// ── Pagination Shape — Companies ──────────────────────────────

test('company list returns correct pagination keys', function () {
    $employer = makeFilterEmployer();
    $company = makeCompany((string) $employer->_id);

    $response = $this->getJson('/api/companies');

    $response->assertStatus(200)
             ->assertJsonStructure([
                 'data',
                 'current_page',
                 'per_page',
                 'total',
                 'total_pages',
                 'next_page',
                 'prev_page',
                 'next_page_url',
                 'prev_page_url',
             ]);

    expect($response->json('current_page'))->toBe(1);
    expect($response->json('prev_page'))->toBeNull();

    $company->delete();
    $employer->delete();
});

test('company list items include open_positions count', function () {
    $employer = makeFilterEmployer();
    $company = makeCompany((string) $employer->_id);
    $job = makeJob((string) $employer->_id);

    $response = $this->getJson('/api/companies');

    $response->assertStatus(200);
    foreach ($response->json('data') as $c) {
        expect($c)->toHaveKey('open_positions');
    }

    $job->delete();
    $company->delete();
    $employer->delete();
});

// ── Company Filters ───────────────────────────────────────────

test('filter companies by search name', function () {
    $employer = makeFilterEmployer();
    $company = makeCompany((string) $employer->_id, ['name' => 'UniqueCompanyXYZ']);

    $response = $this->getJson('/api/companies?search=UniqueCompanyXYZ');

    $response->assertStatus(200);
    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toContain('UniqueCompanyXYZ');

    $company->delete();
    $employer->delete();
});

test('filter companies by search location', function () {
    $employer = makeFilterEmployer();
    $company = makeCompany((string) $employer->_id, ['location' => 'Tripoli, Lebanon']);

    $response = $this->getJson('/api/companies?search=Tripoli');

    $response->assertStatus(200);
    $found = collect($response->json('data'))->first(fn($c) => str_contains($c['location'] ?? '', 'Tripoli'));
    expect($found)->not->toBeNull();

    $company->delete();
    $employer->delete();
});

test('filter companies by industry', function () {
    $employer = makeFilterEmployer();
    $company = makeCompany((string) $employer->_id, ['industry' => 'Healthcare']);

    $response = $this->getJson('/api/companies?industry=Healthcare');

    $response->assertStatus(200);
    foreach ($response->json('data') as $c) {
        expect(strtolower($c['industry']))->toContain('healthcare');
    }

    $company->delete();
    $employer->delete();
});

test('filter companies by min_rating', function () {
    $employer = makeFilterEmployer();
    $company = makeCompany((string) $employer->_id, ['rating' => 4.8]);

    $response = $this->getJson('/api/companies?min_rating=4.5');

    $response->assertStatus(200);
    foreach ($response->json('data') as $c) {
        expect($c['rating'])->toBeGreaterThanOrEqual(4.5);
    }

    $company->delete();
    $employer->delete();
});

test('filter companies by company_size', function () {
    $employer = makeFilterEmployer();
    $company = makeCompany((string) $employer->_id, ['company_size' => '500-1000 employees']);

    $response = $this->getJson('/api/companies?company_size=500-1000');

    $response->assertStatus(200);
    $found = collect($response->json('data'))->first(fn($c) => str_contains($c['company_size'] ?? '', '500-1000'));
    expect($found)->not->toBeNull();

    $company->delete();
    $employer->delete();
});

test('company list respects per_page parameter', function () {
    $employers = collect(range(1, 4))->map(function ($i) {
        $emp = makeFilterEmployer();
        makeCompany((string) $emp->_id, ['name' => "Company $i"]);
        return $emp;
    });

    $response = $this->getJson('/api/companies?per_page=2');

    $response->assertStatus(200);
    expect(count($response->json('data')))->toBeLessThanOrEqual(2);
    expect($response->json('per_page'))->toBe(2);

    $employers->each(function ($emp) {
        CompanyProfile::where('employer_id', (string) $emp->_id)->delete();
        $emp->delete();
    });
});
