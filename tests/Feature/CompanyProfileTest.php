<?php

// ============================================================
// Tests for company profile endpoints.
// Covers: upsert (create + update), company_size enum validation,
// open_positions count, filters, pagination, 404s, auth guards,
// rating field protection, enriched show fields.
// ============================================================

use App\Models\CompanyProfile;
use App\Models\JobPost;
use App\Models\User;

// ── Helpers ───────────────────────────────────────────────────

function cpEmployer(): array
{
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);
    return [$employer, $token];
}

function cpSeeker(): array
{
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);
    return [$seeker, $token];
}

function cpProfile(string $employerId, array $overrides = []): CompanyProfile
{
    return CompanyProfile::updateOrCreate(
        ['employer_id' => $employerId],
        array_merge([
            'name'         => 'Test Corp',
            'slug'         => 'test-corp-' . uniqid(),
            'description'  => 'A test company.',
            'city'         => 'Beirut',
            'country'      => 'Lebanon',
            'company_size' => '10_to_50',
            'industry'     => 'Technology',
            'rating'       => 4.0,
            'review_count' => 50,
        ], $overrides)
    );
}

// ── Upsert: POST/PUT /api/employer/company ────────────────────

test('employer can create company profile', function () {
    [$employer, $token] = cpEmployer();

    $response = $this->withToken($token)->postJson('/api/employer/company', [
        'name'         => 'New Corp',
        'company_size' => 'less_than_10',
        'industry'     => 'Technology',
        'city'         => 'Damascus',
        'country'      => 'Syria',
    ]);

    $response->assertStatus(201)
             ->assertJsonPath('name', 'New Corp')
             ->assertJsonPath('company_size', 'less_than_10')
             ->assertJsonPath('city', 'Damascus');

    CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('upsert updates existing profile on second call', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', [
        'name'     => 'Original Name',
        'industry' => 'Finance',
    ]);

    $this->withToken($token)->putJson('/api/employer/company', [
        'name'     => 'Updated Name',
        'industry' => 'Technology',
    ])->assertStatus(200)
      ->assertJsonPath('name', 'Updated Name')
      ->assertJsonPath('industry', 'Technology');

    expect(CompanyProfile::where('employer_id', (string) $employer->_id)->count())->toBe(1);

    CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('upsert requires name on first creation', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', [
        'industry' => 'Tech',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);

    $employer->delete();
});

test('upsert rejects invalid company_size enum', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', [
        'name'         => 'Corp',
        'company_size' => '100-500',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['company_size']]);

    $employer->delete();
});

test('upsert accepts all valid company_size enum values', function () {
    [$employer, $token] = cpEmployer();

    foreach (CompanyProfile::SIZES as $size) {
        $this->withToken($token)->postJson('/api/employer/company', [
            'name'         => 'Corp',
            'company_size' => $size,
        ])->assertStatus(201);
        CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    }

    $employer->delete();
});

test('upsert response includes open_positions', function () {
    [$employer, $token] = cpEmployer();

    $response = $this->withToken($token)->postJson('/api/employer/company', [
        'name' => 'Minimal Corp',
    ]);

    $response->assertStatus(201)->assertJsonStructure(['open_positions']);

    CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('non-employer cannot create company profile', function () {
    [, $token] = cpSeeker();

    $this->withToken($token)->postJson('/api/employer/company', [
        'name' => 'Sneaky Corp',
    ])->assertStatus(403);
});

test('unauthenticated user cannot create company profile', function () {
    $this->postJson('/api/employer/company', ['name' => 'Ghost Corp'])->assertStatus(401);
});

// ── Show: GET /api/companies/{id} ─────────────────────────────

test('public show returns company profile with open_positions', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id);

    $this->getJson("/api/companies/{$profile->_id}")
         ->assertStatus(200)
         ->assertJsonPath('name', 'Test Corp')
         ->assertJsonStructure(['open_positions']);

    $profile->delete(); $employer->delete();
});

test('public show returns 404 for unknown id', function () {
    $this->getJson('/api/companies/000000000000000000000000')->assertStatus(404);
});

test('open_positions count reflects active job posts only', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id);

    $active   = JobPost::create(['title' => 'Active', 'description' => 'D', 'company_name' => 'C', 'job_type' => 'full_time', 'city' => 'Beirut', 'vacancies' => 1, 'communication_method' => 'by_forsa', 'employer_id' => (string) $employer->_id, 'is_active' => true]);
    $inactive = JobPost::create(['title' => 'Inactive', 'description' => 'D', 'company_name' => 'C', 'job_type' => 'full_time', 'city' => 'Beirut', 'vacancies' => 1, 'communication_method' => 'by_forsa', 'employer_id' => (string) $employer->_id, 'is_active' => false]);

    $response = $this->getJson("/api/companies/{$profile->_id}")->assertStatus(200);
    expect($response->json('open_positions'))->toBe(1);

    $active->delete(); $inactive->delete(); $profile->delete(); $employer->delete();
});

// ── Index: GET /api/companies ─────────────────────────────────

test('public company list returns pagination shape', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id);

    $this->getJson('/api/companies')
         ->assertStatus(200)
         ->assertJsonStructure([
             'data', 'current_page', 'per_page', 'total',
             'total_pages', 'next_page', 'prev_page',
         ]);

    $profile->delete(); $employer->delete();
});

test('filter companies by search matches name', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id, ['name' => 'UniqueSearchCorp']);

    $response = $this->getJson('/api/companies?search=UniqueSearchCorp')->assertStatus(200);
    $names = collect($response->json('data'))->pluck('name')->toArray();
    expect($names)->toContain('UniqueSearchCorp');

    $profile->delete(); $employer->delete();
});

test('filter companies by search matches city', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id, ['city' => 'Tripoli']);

    $response = $this->getJson('/api/companies?search=Tripoli')->assertStatus(200);
    $found = collect($response->json('data'))->first(fn($c) => str_contains($c['city'] ?? '', 'Tripoli'));
    expect($found)->not->toBeNull();

    $profile->delete(); $employer->delete();
});

test('filter companies by industry', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id, ['industry' => 'Healthcare']);

    $response = $this->getJson('/api/companies?industry=Healthcare')->assertStatus(200);
    foreach ($response->json('data') as $c) {
        expect(strtolower($c['industry']))->toContain('healthcare');
    }

    $profile->delete(); $employer->delete();
});

test('filter companies by min_rating', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id, ['rating' => 4.9]);

    $response = $this->getJson('/api/companies?min_rating=4.5')->assertStatus(200);
    foreach ($response->json('data') as $c) {
        expect($c['rating'])->toBeGreaterThanOrEqual(4.5);
    }

    $profile->delete(); $employer->delete();
});

test('filter companies by company_size enum', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id, ['company_size' => '501_to_1000']);

    $response = $this->getJson('/api/companies?company_size=501_to_1000')->assertStatus(200);
    $found = collect($response->json('data'))->first(fn($c) => ($c['company_size'] ?? '') === '501_to_1000');
    expect($found)->not->toBeNull();

    $profile->delete(); $employer->delete();
});

test('company list respects per_page parameter', function () {
    $employers = collect(range(1, 4))->map(function ($i) {
        $emp = User::factory()->employer()->create();
        cpProfile((string) $emp->_id, ['name' => "PerPage Corp $i"]);
        return $emp;
    });

    $response = $this->getJson('/api/companies?per_page=2')->assertStatus(200);
    expect(count($response->json('data')))->toBeLessThanOrEqual(2);
    expect($response->json('per_page'))->toBe(2);

    $employers->each(function ($emp) {
        CompanyProfile::where('employer_id', (string) $emp->_id)->delete();
        $emp->delete();
    });
});

// ── Enriched show fields ──────────────────────────────────────

test('show includes jobs array and reviews array', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', ['name' => 'JobShapeCorp']);
    $profile = CompanyProfile::where('employer_id', (string) $employer->_id)->first();

    $job = JobPost::create([
        'title'       => 'Senior Dev',
        'description' => 'Build stuff',
        'company_name'=> 'JobShapeCorp',
        'job_type'    => 'full_time',
        'city'        => 'Beirut',
        'vacancies'   => 1,
        'communication_method' => 'by_forsa',
        'employer_id' => (string) $employer->_id,
        'is_active'   => true,
    ]);

    $response = $this->getJson("/api/companies/{$profile->_id}")->assertStatus(200);
    expect($response->json('jobs'))->toHaveCount(1);
    expect($response->json('reviews'))->toBeArray();

    $job->delete(); $profile->delete(); $employer->delete();
});

test('show excludes inactive jobs from jobs array', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', ['name' => 'InactiveJobCorp']);
    $profile = CompanyProfile::where('employer_id', (string) $employer->_id)->first();

    $inactive = JobPost::create([
        'title'       => 'Old Role',
        'description' => 'D',
        'company_name'=> 'InactiveJobCorp',
        'job_type'    => 'full_time',
        'city'        => 'Beirut',
        'vacancies'   => 1,
        'communication_method' => 'by_forsa',
        'employer_id' => (string) $employer->_id,
        'is_active'   => false,
    ]);

    $response = $this->getJson("/api/companies/{$profile->_id}")->assertStatus(200);
    expect($response->json('jobs'))->toHaveCount(0);

    $inactive->delete(); $profile->delete(); $employer->delete();
});

test('show returns empty reviews array when none stored', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id);

    $response = $this->getJson("/api/companies/{$profile->_id}")->assertStatus(200);
    expect($response->json('reviews'))->toBeArray()->toHaveCount(0);

    $profile->delete(); $employer->delete();
});

// ── Rating Fields Protection ──────────────────────────────────

test('employer cannot set rating or review fields during creation', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', [
        'name'            => 'SelfRateCorp',
        'rating'          => 5.0,
        'review_count'    => 999,
        'would_recommend' => 100,
        'reviews'         => [['id' => '1', 'rating' => 5, 'user_name' => 'Fake']],
        'category_ratings'=> ['compensation' => 5.0],
    ])->assertStatus(201);

    // Controller initialises these to 0, not to the values the employer sent
    $profile = CompanyProfile::where('employer_id', (string) $employer->_id)->first();
    expect($profile->review_count)->not->toBe(999);

    $profile->delete(); $employer->delete();
});

test('rating fields are not overrideable during updates', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', ['name' => 'UpdateRateCorp']);
    $profile = CompanyProfile::where('employer_id', (string) $employer->_id)->first();
    $originalRating = $profile->rating;

    $this->withToken($token)->putJson('/api/employer/company', [
        'name'   => 'UpdateRateCorp v2',
        'rating' => 4.99,
    ])->assertStatus(200);

    $profile->refresh();
    expect($profile->rating)->toBe($originalRating); // unchanged

    $profile->delete(); $employer->delete();
});
