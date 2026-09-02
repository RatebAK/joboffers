<?php

// Covers category validation on job post create/update and the freeform
// city/role acceptance rules (lookup-tables feature, properties P5/P6/P10).

use App\Models\Category;
use App\Models\JobPost;

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

beforeEach(function () {
    [$this->employer, $this->token] = userWithToken('employer');
    createCompanyFor($this->employer, ['name' => 'ValidCo']);
});

// ── P5. Invalid category rejected ──────────────────────────────────

test('job post with nonexistent category returns 422 (P5)', function () {
    $this->withToken($this->token)
        ->postJson('/api/employer/jobs', validJobPayload(['category' => 'NonExistentCategory_'.uniqid()]))
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['category']]);
});

test('job post update with nonexistent category returns 422 (P5)', function () {
    $job = createJob($this->employer);

    $this->withToken($this->token)
        ->putJson("/api/employer/jobs/{$job->_id}", ['category' => 'FakeCategory_'.uniqid()])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['category']]);
});

// ── P6. Valid category accepted ────────────────────────────────────

test('job post with valid category is accepted (P6)', function () {
    Category::create(['name' => 'Technology']);

    $res = $this->withToken($this->token)
        ->postJson('/api/employer/jobs', validJobPayload(['category' => 'Technology']))
        ->assertCreated();

    expect($res->json('category'))->toBe('Technology');
});

test('job post with no category is accepted (P6 — category optional)', function () {
    $this->withToken($this->token)
        ->postJson('/api/employer/jobs', validJobPayload())
        ->assertCreated();
});

test('job post update to valid category is accepted (P6)', function () {
    Category::create(['name' => 'Engineering']);
    $job = createJob($this->employer);

    $this->withToken($this->token)
        ->putJson("/api/employer/jobs/{$job->_id}", ['category' => 'Engineering'])
        ->assertOk();
});

// ── P10. Freeform city and role values always accepted ─────────────

test('job post with freeform city not in cities collection is accepted (P10)', function () {
    $this->withToken($this->token)
        ->postJson('/api/employer/jobs', validJobPayload([
            'title' => 'Freeform Job',
            'city'  => 'SomeObscureVillage_'.uniqid(),
        ]))
        ->assertCreated();
});

test('job post with freeform roles not in roles collection is accepted (P10)', function () {
    $this->withToken($this->token)
        ->postJson('/api/employer/jobs', validJobPayload([
            'title' => 'Freeform Job',
            'roles' => ['SomeRandomRole_'.uniqid(), 'AnotherRole_'.uniqid()],
        ]))
        ->assertCreated();
});
