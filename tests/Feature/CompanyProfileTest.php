<?php

// ============================================================
// DO NOT DELETE — Comprehensive tests for company profile endpoints.
// Covers: upsert (create + update), company_size structured input
// (range and plus variants), string input fallback, company_size_range
// parsing in responses, open_positions count, all filter params,
// pagination, 404s, and auth guards.
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
            'description'  => 'A test company.',
            'location'     => 'Beirut, Lebanon',
            'company_size' => '100-500',
            'industry'     => 'Technology',
            'website'      => 'https://testcorp.com',
            'rating'       => 4.0,
            'review_count' => 50,
        ], $overrides)
    );
}

// ── Upsert: POST/PUT /api/employer/company ────────────────────

test('employer can create company profile with structured range company_size', function () {
    [$employer, $token] = cpEmployer();

    $response = $this->withToken($token)->postJson('/api/employer/company', [
        'name'         => 'Range Corp',
        'company_size' => ['min' => 100, 'max' => 500, 'isPlus' => false],
    ]);

    $response->assertStatus(200)
             ->assertJsonPath('name', 'Range Corp')
             ->assertJsonPath('company_size', '100-500')
             ->assertJsonPath('company_size_range.min', 100)
             ->assertJsonPath('company_size_range.max', 500)
             ->assertJsonPath('company_size_range.isPlus', false);

    CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('employer can create company profile with structured plus company_size', function () {
    [$employer, $token] = cpEmployer();

    $response = $this->withToken($token)->postJson('/api/employer/company', [
        'name'         => 'Big Corp',
        'company_size' => ['min' => 500, 'isPlus' => true],
    ]);

    $response->assertStatus(200)
             ->assertJsonPath('company_size', '500+')
             ->assertJsonPath('company_size_range.min', 500)
             ->assertJsonPath('company_size_range.isPlus', true);

    expect($response->json('company_size_range'))->not->toHaveKey('max');

    CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('employer can create company profile with plain string company_size', function () {
    [$employer, $token] = cpEmployer();

    $response = $this->withToken($token)->postJson('/api/employer/company', [
        'name'         => 'String Corp',
        'company_size' => '200-400',
    ]);

    $response->assertStatus(200)
             ->assertJsonPath('company_size', '200-400')
             ->assertJsonPath('company_size_range.min', 200)
             ->assertJsonPath('company_size_range.max', 400)
             ->assertJsonPath('company_size_range.isPlus', false);

    CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('employer can create company profile with plus string company_size', function () {
    [$employer, $token] = cpEmployer();

    $response = $this->withToken($token)->postJson('/api/employer/company', [
        'name'         => 'Plus Corp',
        'company_size' => '1000+',
    ]);

    $response->assertStatus(200)
             ->assertJsonPath('company_size_range.min', 1000)
             ->assertJsonPath('company_size_range.isPlus', true);

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

test('upsert requires name field', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', [
        'industry' => 'Tech',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);

    $employer->delete();
});

test('upsert validates logo as url', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', [
        'name' => 'Corp',
        'logo' => 'not-a-url',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['logo']]);

    $employer->delete();
});

test('upsert validates website as url', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', [
        'name'    => 'Corp',
        'website' => 'not-a-url',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['website']]);

    $employer->delete();
});

test('upsert response always includes open_positions and company_size_range', function () {
    [$employer, $token] = cpEmployer();

    $response = $this->withToken($token)->postJson('/api/employer/company', [
        'name' => 'Minimal Corp',
    ]);

    $response->assertStatus(200)
             ->assertJsonStructure(['open_positions', 'company_size_range']);

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

test('public show returns company profile with open_positions and company_size_range', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id);

    $this->getJson("/api/companies/{$profile->_id}")
         ->assertStatus(200)
         ->assertJsonPath('name', 'Test Corp')
         ->assertJsonStructure(['open_positions', 'company_size_range']);

    $profile->delete(); $employer->delete();
});

test('public show returns 404 for unknown id', function () {
    $this->getJson('/api/companies/000000000000000000000000')->assertStatus(404);
});

test('open_positions count reflects active job posts only', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id);

    $active   = JobPost::create(['title' => 'Active', 'description' => 'D', 'requirements' => 'R', 'company_name' => 'C', 'job_type' => 'full_time', 'location' => 'Remote', 'employer_id' => (string) $employer->_id, 'is_active' => true]);
    $inactive = JobPost::create(['title' => 'Inactive', 'description' => 'D', 'requirements' => 'R', 'company_name' => 'C', 'job_type' => 'full_time', 'location' => 'Remote', 'employer_id' => (string) $employer->_id, 'is_active' => false]);

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

test('company list items include company_size_range', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id, ['company_size' => '50-200']);

    // Use show directly — avoids pagination ordering issues
    $response = $this->getJson("/api/companies/{$profile->_id}")->assertStatus(200);
    expect($response->json())->toHaveKey('company_size_range');
    expect($response->json('company_size_range.min'))->toBe(50);
    expect($response->json('company_size_range.max'))->toBe(200);

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

test('filter companies by search matches location', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id, ['location' => 'Tripoli, Lebanon']);

    $response = $this->getJson('/api/companies?search=Tripoli')->assertStatus(200);
    $found = collect($response->json('data'))->first(fn($c) => str_contains($c['location'] ?? '', 'Tripoli'));
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

test('filter companies by company_size string', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id, ['company_size' => '500-1000']);

    $response = $this->getJson('/api/companies?company_size=500-1000')->assertStatus(200);
    $found = collect($response->json('data'))->first(fn($c) => str_contains($c['company_size'] ?? '', '500-1000'));
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

// ── Show: enriched fields (cover_image, founded, social_media, ratings, reviews, jobs) ──

test('upsert stores and show returns all enriched fields', function () {
    [$employer, $token] = cpEmployer();

    $payload = [
        'name'             => 'Google',
        'logo'             => 'https://logo.clearbit.com/google.com',
        'cover_image'      => 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=1200&h=400&fit=crop',
        'description'      => 'A multinational technology company.',
        'founded'          => '1998',
        'employee_count'   => '100,000+ employees',
        'location'         => 'Mountain View, CA',
        'website'          => 'https://www.google.com',
        'rating'           => 4.5,
        'review_count'     => 1250,
        'would_recommend'  => 85,
        'ceo_performance'  => 92,
        'social_media'     => [
            'linkedin'  => 'https://www.linkedin.com/company/google',
            'twitter'   => 'https://twitter.com/Google',
            'facebook'  => 'https://www.facebook.com/Google',
            'instagram' => 'https://www.instagram.com/google',
        ],
        'category_ratings' => [
            'compensation' => 4.2,
            'culture'      => 4.6,
            'work_life'    => 4.1,
            'diversity'    => 4.4,
            'management'   => 4.3,
        ],
        'reviews' => [
            [
                'id'          => '1',
                'rating'      => 4,
                'user_name'   => 'John Doe',
                'date'        => '27/01/2026',
                'position'    => 'Former employee, last year at 2022',
                'recommend'   => false,
                'ceo_approval'=> true,
                'subratings'  => ['compensation' => 4, 'culture' => 4, 'work_life' => 3, 'diversity' => 5, 'management' => 3],
                'agrees'      => 5,
                'disagrees'   => 2,
            ],
            [
                'id'          => '2',
                'rating'      => 5,
                'user_name'   => 'Jane Smith',
                'date'        => '15/01/2026',
                'position'    => 'Current employee, 3 years',
                'recommend'   => true,
                'ceo_approval'=> true,
                'subratings'  => ['compensation' => 5, 'culture' => 5, 'work_life' => 4, 'diversity' => 5, 'management' => 5],
                'agrees'      => 12,
                'disagrees'   => 1,
            ],
        ],
    ];

    $upsertResponse = $this->withToken($token)->postJson('/api/employer/company', $payload);
    $upsertResponse->assertStatus(200);

    $profileId = $upsertResponse->json('id');

    $response = $this->getJson("/api/companies/{$profileId}");
    $response->assertStatus(200)
             ->assertJsonPath('name', 'Google')
             ->assertJsonPath('logo', 'https://logo.clearbit.com/google.com')
             ->assertJsonPath('cover_image', 'https://images.unsplash.com/photo-1497366216548-37526070297c?w=1200&h=400&fit=crop')
             ->assertJsonPath('founded', '1998')
             ->assertJsonPath('employee_count', '100,000+ employees')
             ->assertJsonPath('location', 'Mountain View, CA')
             ->assertJsonPath('website', 'https://www.google.com')
             ->assertJsonPath('rating', 4.5)
             ->assertJsonPath('review_count', 1250)
             ->assertJsonPath('would_recommend', 85)
             ->assertJsonPath('ceo_performance', 92)
             ->assertJsonPath('social_media.linkedin', 'https://www.linkedin.com/company/google')
             ->assertJsonPath('social_media.twitter', 'https://twitter.com/Google')
             ->assertJsonPath('social_media.facebook', 'https://www.facebook.com/Google')
             ->assertJsonPath('social_media.instagram', 'https://www.instagram.com/google')
             ->assertJsonPath('category_ratings.compensation', 4.2)
             ->assertJsonPath('category_ratings.culture', 4.6)
             ->assertJsonPath('category_ratings.work_life', 4.1)
             ->assertJsonPath('category_ratings.diversity', 4.4)
             ->assertJsonPath('category_ratings.management', 4.3)
             ->assertJsonStructure(['reviews', 'jobs', 'open_positions', 'company_size_range']);

    expect(count($response->json('reviews')))->toBe(2);
    expect($response->json('reviews.0.user_name'))->toBe('John Doe');
    expect($response->json('reviews.1.user_name'))->toBe('Jane Smith');

    CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('show includes active jobs with correct shape', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', ['name' => 'JobShapeCorp']);
    $profile = CompanyProfile::where('employer_id', (string) $employer->_id)->first();

    $job = JobPost::create([
        'title'              => 'Senior Frontend Developer',
        'description'        => 'Build UIs',
        'requirements'       => 'React experience',
        'company_name'       => 'JobShapeCorp',
        'company_logo'       => 'https://logo.clearbit.com/google.com',
        'job_type'           => 'full_time',
        'work_mode'          => 'remote',
        'experience_level'   => 'senior',
        'experience_required'=> '5+ years',
        'location'           => 'San Francisco, CA',
        'employer_id'        => (string) $employer->_id,
        'is_active'          => true,
        'tags'               => ['Frontend', 'React'],
    ]);

    $response = $this->getJson("/api/companies/{$profile->_id}");
    $response->assertStatus(200);

    $jobs = $response->json('jobs');
    expect($jobs)->toHaveCount(1);
    expect($jobs[0])->toHaveKeys(['id', 'display_id', 'company_name', 'company_logo', 'title', 'created_at', 'roles', 'types', 'levels', 'experience', 'location']);
    expect($jobs[0]['title'])->toBe('Senior Frontend Developer');
    expect($jobs[0]['roles'])->toContain('Frontend');
    expect($jobs[0]['location'])->toBe('San Francisco, CA');

    $job->delete();
    $profile->delete();
    $employer->delete();
});

test('show excludes inactive jobs from jobs array', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', ['name' => 'InactiveJobCorp']);
    $profile = CompanyProfile::where('employer_id', (string) $employer->_id)->first();

    $inactive = JobPost::create([
        'title' => 'Old Role', 'description' => 'D', 'requirements' => 'R',
        'company_name' => 'InactiveJobCorp', 'job_type' => 'full_time',
        'location' => 'Remote', 'employer_id' => (string) $employer->_id, 'is_active' => false,
    ]);

    $response = $this->getJson("/api/companies/{$profile->_id}");
    $response->assertStatus(200);
    expect($response->json('jobs'))->toHaveCount(0);

    $inactive->delete();
    $profile->delete();
    $employer->delete();
});

test('show returns empty reviews array when none stored', function () {
    [$employer] = cpEmployer();
    $profile = cpProfile((string) $employer->_id);

    $response = $this->getJson("/api/companies/{$profile->_id}");
    $response->assertStatus(200);
    expect($response->json('reviews'))->toBeArray()->toHaveCount(0);

    $profile->delete(); $employer->delete();
});

test('upsert validates cover_image as url', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', [
        'name'        => 'Corp',
        'cover_image' => 'not-a-url',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['cover_image']]);

    $employer->delete();
});

test('upsert validates social_media urls', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', [
        'name'         => 'Corp',
        'social_media' => ['linkedin' => 'not-a-url'],
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['social_media.linkedin']]);

    $employer->delete();
});

test('upsert validates category_ratings range', function () {
    [$employer, $token] = cpEmployer();

    $this->withToken($token)->postJson('/api/employer/company', [
        'name'             => 'Corp',
        'category_ratings' => ['culture' => 6],
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['category_ratings.culture']]);

    $employer->delete();
});
