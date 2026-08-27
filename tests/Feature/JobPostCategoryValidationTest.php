<?php

// =============================================================================
// JobPostCategoryValidationTest
//
// Covers:
//   P5. Invalid category value rejected on job post create/update
//   P6. Valid category accepted on job post create/update
//   P10. Freeform city and role values always accepted
// =============================================================================

use App\Models\Category;
use App\Models\CompanyProfile;
use App\Models\JobPost;
use App\Models\User;

// ── Helpers ──────────────────────────────────────────────────────────────────

function catValEmployer(): array
{
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);
    CompanyProfile::create([
        'employer_id' => (string) $employer->_id,
        'name'        => 'ValidCo',
        'slug'        => 'validco-' . uniqid(),
    ]);
    return [$employer, $token];
}

function validJobPayload(array $overrides = []): array
{
    return array_merge([
        'title'                => 'Test Job',
        'description'          => 'Description here.',
        'job_type'             => 'full_time',
        'work_mode'            => 'on_site',
        'city'                 => 'Damascus',
        'vacancies'            => 1,
        'communication_method' => 'by_forsa',
    ], $overrides);
}

afterEach(function () {
    Category::truncate();
    JobPost::where('title', 'Test Job')->delete();
    JobPost::where('title', 'Freeform Job')->delete();
});

// =============================================================================
// P5. Invalid category rejected
// =============================================================================

test('job post with nonexistent category returns 422 (P5)', function () {
    // Feature: lookup-tables, Property 5: invalid category rejected
    [, $token] = catValEmployer();

    $this->withToken($token)
        ->postJson('/api/employer/jobs', validJobPayload(['category' => 'NonExistentCategory_' . uniqid()]))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['category']]);
});

test('job post update with nonexistent category returns 422 (P5)', function () {
    // Feature: lookup-tables, Property 5: invalid category rejected
    [$employer, $token] = catValEmployer();

    // Create a job post first (no category)
    $job = JobPost::create(array_merge(validJobPayload(), [
        'employer_id' => (string) $employer->_id,
        'is_active'   => true,
    ]));

    $this->withToken($token)
        ->putJson("/api/employer/jobs/{$job->_id}", ['category' => 'FakeCategory_' . uniqid()])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['category']]);

    $job->delete();
    $employer->delete();
});

// =============================================================================
// P6. Valid category accepted
// =============================================================================

test('job post with valid category is accepted (P6)', function () {
    // Feature: lookup-tables, Property 6: valid category accepted
    [$employer, $token] = catValEmployer();

    $category = Category::create(['name' => 'Technology']);

    $res = $this->withToken($token)
        ->postJson('/api/employer/jobs', validJobPayload(['category' => 'Technology']))
        ->assertStatus(201);

    expect($res->json('category'))->toBe('Technology');

    JobPost::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('job post with no category is accepted (P6 — category optional)', function () {
    // Feature: lookup-tables, Property 6: valid category accepted
    [$employer, $token] = catValEmployer();

    $this->withToken($token)
        ->postJson('/api/employer/jobs', validJobPayload())
        ->assertStatus(201);

    JobPost::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('job post update to valid category is accepted (P6)', function () {
    // Feature: lookup-tables, Property 6: valid category accepted
    [$employer, $token] = catValEmployer();

    Category::create(['name' => 'Engineering']);

    $job = JobPost::create(array_merge(validJobPayload(), [
        'employer_id' => (string) $employer->_id,
        'is_active'   => true,
    ]));

    $this->withToken($token)
        ->putJson("/api/employer/jobs/{$job->_id}", ['category' => 'Engineering'])
        ->assertStatus(200);

    $job->delete();
    $employer->delete();
});

// =============================================================================
// P10. Freeform city and role values always accepted
// =============================================================================

test('job post with freeform city not in cities collection is accepted (P10)', function () {
    // Feature: lookup-tables, Property 10: freeform city and role values accepted
    [$employer, $token] = catValEmployer();

    $freeformCity = 'SomeObscureVillage_' . uniqid();

    $this->withToken($token)
        ->postJson('/api/employer/jobs', validJobPayload([
            'title' => 'Freeform Job',
            'city'  => $freeformCity,
        ]))
        ->assertStatus(201);

    JobPost::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('job post with freeform roles not in roles collection is accepted (P10)', function () {
    // Feature: lookup-tables, Property 10: freeform city and role values accepted
    [$employer, $token] = catValEmployer();

    $freeformRole = 'SomeRandomRole_' . uniqid();

    $this->withToken($token)
        ->postJson('/api/employer/jobs', validJobPayload([
            'title' => 'Freeform Job',
            'roles' => [$freeformRole, 'AnotherRole_' . uniqid()],
        ]))
        ->assertStatus(201);

    JobPost::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});
