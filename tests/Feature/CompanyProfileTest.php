<?php

// =============================================================================
// CompanyProfileTest — public company endpoints and employer upsert.
//
// Covers: upsert (create + update), company_size enum, open_positions count,
// filters, pagination, 404s, auth guards, and rating-field protection.
// =============================================================================

use App\Models\CompanyProfile;

/** A company profile for the employer, with sensible defaults. */
function companyProfile(string $employerId, array $overrides = []): CompanyProfile
{
    return CompanyProfile::updateOrCreate(
        ['employer_id' => $employerId],
        array_merge([
            'name'         => 'Test Corp',
            'slug'         => 'test-corp-'.uniqid(),
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

// ── Upsert: POST/PUT /api/employer/company ───────────────────────────────

test('an employer can create a company profile', function () {
    [, $token] = userWithToken('employer');

    $this->withToken($token)->postJson('/api/employer/company', [
        'name'         => 'New Corp',
        'company_size' => 'less_than_10',
        'industry'     => 'Technology',
        'city'         => 'Damascus',
        'country'      => 'Syria',
    ])
        ->assertCreated()
        ->assertJsonPath('name', 'New Corp')
        ->assertJsonPath('company_size', 'less_than_10')
        ->assertJsonPath('city', 'Damascus');
});

test('a second upsert updates the existing profile instead of creating another', function () {
    [$employer, $token] = userWithToken('employer');

    $this->withToken($token)->postJson('/api/employer/company', ['name' => 'Original Name', 'industry' => 'Finance']);
    $this->withToken($token)->putJson('/api/employer/company', ['name' => 'Updated Name', 'industry' => 'Technology'])
        ->assertOk()
        ->assertJsonPath('name', 'Updated Name')
        ->assertJsonPath('industry', 'Technology');

    expect(CompanyProfile::where('employer_id', (string) $employer->_id)->count())->toBe(1);
});

test('creating a company requires a name', function () {
    [, $token] = userWithToken('employer');

    $this->withToken($token)->postJson('/api/employer/company', ['industry' => 'Tech'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);
});

test('an invalid company_size is rejected', function () {
    [, $token] = userWithToken('employer');

    $this->withToken($token)->postJson('/api/employer/company', ['name' => 'Corp', 'company_size' => '100-500'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['company_size']]);
});

test('every valid company_size value is accepted', function (string $size) {
    [, $token] = userWithToken('employer');

    $this->withToken($token)->postJson('/api/employer/company', ['name' => 'Corp', 'company_size' => $size])
        ->assertCreated();
})->with(CompanyProfile::SIZES);

test('the upsert response includes open_positions', function () {
    [, $token] = userWithToken('employer');

    $this->withToken($token)->postJson('/api/employer/company', ['name' => 'Minimal Corp'])
        ->assertCreated()
        ->assertJsonStructure(['open_positions']);
});

test('a non-employer cannot create a company profile', function () {
    $this->withToken(tokenFor('employee'))
        ->postJson('/api/employer/company', ['name' => 'Sneaky Corp'])
        ->assertForbidden();
});

test('an unauthenticated user cannot create a company profile', function () {
    $this->postJson('/api/employer/company', ['name' => 'Ghost Corp'])->assertUnauthorized();
});

// ── Show: GET /api/companies/{id} ────────────────────────────────────────

test('the public show endpoint returns the profile with open_positions', function () {
    $employer = createUser('employer');
    $profile  = companyProfile((string) $employer->_id);

    $this->getJson("/api/companies/{$profile->_id}")
        ->assertOk()
        ->assertJsonPath('name', 'Test Corp')
        ->assertJsonStructure(['open_positions']);
});

test('the public show endpoint returns 404 for an unknown id', function () {
    $this->getJson('/api/companies/000000000000000000000000')->assertNotFound();
});

test('open_positions counts only active job posts', function () {
    $employer = createUser('employer');
    $profile  = companyProfile((string) $employer->_id);
    createJob($employer, ['is_active' => true]);
    createJob($employer, ['is_active' => false]);

    expect($this->getJson("/api/companies/{$profile->_id}")->assertOk()->json('open_positions'))->toBe(1);
});

// ── Index: GET /api/companies ────────────────────────────────────────────

test('the public company list has the standard pagination shape', function () {
    companyProfile((string) createUser('employer')->_id);

    $this->getJson('/api/companies')
        ->assertOk()
        ->assertJsonStructure(['data', 'current_page', 'per_page', 'total', 'total_pages', 'next_page', 'prev_page']);
});

test('companies can be searched by name', function () {
    companyProfile((string) createUser('employer')->_id, ['name' => 'UniqueSearchCorp']);

    $names = collect($this->getJson('/api/companies?search=UniqueSearchCorp')->assertOk()->json('data'))->pluck('name');

    expect($names)->toContain('UniqueSearchCorp');
});

test('companies can be searched by city', function () {
    companyProfile((string) createUser('employer')->_id, ['city' => 'Tripoli']);

    $found = collect($this->getJson('/api/companies?search=Tripoli')->assertOk()->json('data'))
        ->first(fn ($c) => str_contains($c['city'] ?? '', 'Tripoli'));

    expect($found)->not->toBeNull();
});

test('companies can be filtered by industry', function () {
    companyProfile((string) createUser('employer')->_id, ['industry' => 'Healthcare']);

    foreach ($this->getJson('/api/companies?industry=Healthcare')->assertOk()->json('data') as $c) {
        expect(strtolower($c['industry']))->toContain('healthcare');
    }
});

test('companies can be filtered by minimum rating', function () {
    companyProfile((string) createUser('employer')->_id, ['rating' => 4.9]);

    foreach ($this->getJson('/api/companies?min_rating=4.5')->assertOk()->json('data') as $c) {
        expect($c['rating'])->toBeGreaterThanOrEqual(4.5);
    }
});

test('companies can be filtered by company_size', function () {
    companyProfile((string) createUser('employer')->_id, ['company_size' => '501_to_1000']);

    $found = collect($this->getJson('/api/companies?company_size=501_to_1000')->assertOk()->json('data'))
        ->first(fn ($c) => ($c['company_size'] ?? '') === '501_to_1000');

    expect($found)->not->toBeNull();
});

test('the company list respects the per_page parameter', function () {
    foreach (range(1, 4) as $i) {
        companyProfile((string) createUser('employer')->_id, ['name' => "PerPage Corp {$i}"]);
    }

    $response = $this->getJson('/api/companies?per_page=2')->assertOk();

    expect(count($response->json('data')))->toBeLessThanOrEqual(2)
        ->and($response->json('per_page'))->toBe(2);
});

// ── Enriched show fields ─────────────────────────────────────────────────

test('the show endpoint includes active jobs and a reviews array', function () {
    $employer = createUser('employer');
    $profile  = companyProfile((string) $employer->_id, ['name' => 'JobShapeCorp']);
    createJob($employer, ['company_name' => 'JobShapeCorp', 'is_active' => true]);

    $response = $this->getJson("/api/companies/{$profile->_id}")->assertOk();

    expect($response->json('jobs'))->toHaveCount(1)
        ->and($response->json('reviews'))->toBeArray();
});

test('the show endpoint excludes inactive jobs', function () {
    $employer = createUser('employer');
    $profile  = companyProfile((string) $employer->_id);
    createJob($employer, ['is_active' => false]);

    expect($this->getJson("/api/companies/{$profile->_id}")->assertOk()->json('jobs'))->toHaveCount(0);
});

test('the show endpoint returns an empty reviews array when none exist', function () {
    $profile = companyProfile((string) createUser('employer')->_id);

    expect($this->getJson("/api/companies/{$profile->_id}")->assertOk()->json('reviews'))->toBeArray()->toHaveCount(0);
});

// ── Rating-field protection ──────────────────────────────────────────────

test('an employer cannot seed their own rating fields on creation', function () {
    [$employer, $token] = userWithToken('employer');

    $this->withToken($token)->postJson('/api/employer/company', [
        'name'         => 'SelfRateCorp',
        'rating'       => 5.0,
        'review_count' => 999,
    ])->assertCreated();

    expect(CompanyProfile::where('employer_id', (string) $employer->_id)->first()->review_count)->not->toBe(999);
});

test('rating fields cannot be overridden on update', function () {
    [$employer, $token] = userWithToken('employer');
    $this->withToken($token)->postJson('/api/employer/company', ['name' => 'UpdateRateCorp']);
    $original = CompanyProfile::where('employer_id', (string) $employer->_id)->first()->rating;

    $this->withToken($token)->putJson('/api/employer/company', ['name' => 'UpdateRateCorp v2', 'rating' => 4.99])->assertOk();

    expect(CompanyProfile::where('employer_id', (string) $employer->_id)->first()->rating)->toBe($original);
});
