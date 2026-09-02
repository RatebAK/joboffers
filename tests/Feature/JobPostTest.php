<?php

// =============================================================================
// JobPostTest
//
// Covers job post endpoints: public listing and show, job_id generation,
// employer CRUD, activation/deactivation, ownership enforcement, filters,
// pagination edges, and the roles field.
// =============================================================================

use App\Models\Application;

/** Employer + token that already owns a company profile, so store() succeeds. */
function employerWithCompany(): array
{
    [$employer, $token] = userWithToken('employer');
    createCompanyFor($employer, ['name' => 'TestCo']);

    return [$employer, $token];
}

/** Minimal valid job creation payload, with overrides. */
function jobPayload(array $overrides = []): array
{
    return array_merge([
        'title'                => 'New Role',
        'description'          => 'Desc.',
        'job_type'             => 'full_time',
        'vacancies'            => 1,
        'city'                 => 'Beirut',
        'communication_method' => 'by_forsa',
    ], $overrides);
}

// ── Public: GET /api/jobs ────────────────────────────────────────────────

test('the public job list returns only active posts', function () {
    $employer = createUser('employer');
    $keyword  = uniqid('JOB_');
    createJob($employer, ['is_active' => true, 'title' => "{$keyword}_active"]);
    createJob($employer, ['is_active' => false, 'title' => "{$keyword}_inactive"]);

    $titles = collect(
        $this->getJson("/api/jobs?keyword={$keyword}&per_page=100")->assertOk()->json('data')
    )->pluck('title');

    expect($titles)->toContain("{$keyword}_active")
        ->not->toContain("{$keyword}_inactive");
});

test('the public job list has the standard pagination shape', function () {
    createJob(createUser('employer'));

    $this->getJson('/api/jobs')
        ->assertOk()
        ->assertJsonStructure([
            'data', 'current_page', 'per_page', 'total',
            'total_pages', 'next_page', 'prev_page',
        ]);
});

test('the public job list caps per_page at 100', function () {
    expect($this->getJson('/api/jobs?per_page=999')->assertOk()->json('per_page'))
        ->toBeLessThanOrEqual(100);
});

// ── Public: GET /api/jobs/{id} ───────────────────────────────────────────

test('the public show endpoint returns a single job post', function () {
    $job = createJob(createUser('employer'), ['title' => 'Test Engineer']);

    $this->getJson("/api/jobs/{$job->_id}")
        ->assertOk()
        ->assertJsonPath('title', 'Test Engineer')
        ->assertJsonPath('job_type', 'full_time');
});

test('the public show endpoint returns 404 for an unknown id', function () {
    $this->getJson('/api/jobs/000000000000000000000000')->assertNotFound();
});

test('the public show endpoint returns inactive posts by id', function () {
    // Only the list filters by is_active; show does not.
    $job = createJob(createUser('employer'), ['is_active' => false]);

    $this->getJson("/api/jobs/{$job->_id}")->assertOk();
});

test('the public show endpoint returns the roles field', function () {
    $job = createJob(createUser('employer'), ['roles' => ['DevOps', 'AWS']]);

    expect($this->getJson("/api/jobs/{$job->_id}")->assertOk()->json('roles'))
        ->toContain('DevOps');
});

// ── Employer: POST /api/employer/jobs ────────────────────────────────────

test('creating a job assigns a human-readable job_id', function () {
    [, $token] = employerWithCompany();

    $jobId = $this->withToken($token)
        ->postJson('/api/employer/jobs', jobPayload(['job_type' => 'contract']))
        ->assertCreated()
        ->json('job_id');

    expect($jobId)->toStartWith('JOB-');
});

test('creating a job sets employer_id from the authenticated user', function () {
    [$employer, $token] = employerWithCompany();

    $this->withToken($token)
        ->postJson('/api/employer/jobs', jobPayload())
        ->assertCreated()
        ->assertJsonPath('employer_id', (string) $employer->_id);
});

test('creating a job validates the job_type enum', function () {
    [, $token] = employerWithCompany();

    $this->withToken($token)
        ->postJson('/api/employer/jobs', jobPayload(['job_type' => 'gig_economy']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('job_type');
});

test('creating a job validates the work_mode enum', function () {
    [, $token] = employerWithCompany();

    $this->withToken($token)
        ->postJson('/api/employer/jobs', jobPayload(['work_mode' => 'moon']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('work_mode');
});

test('creating a job validates the job_level enum', function () {
    [, $token] = employerWithCompany();

    $this->withToken($token)
        ->postJson('/api/employer/jobs', jobPayload(['job_level' => 'god']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('job_level');
});

test('creating a job accepts optional salary and tags', function () {
    [, $token] = employerWithCompany();

    $response = $this->withToken($token)->postJson('/api/employer/jobs', jobPayload([
        'salary_from' => 3000,
        'salary_to'   => 6000,
        'currency'    => 'USD',
        'tags'        => ['Laravel', 'PHP'],
    ]))->assertCreated();

    expect($response->json('salary_from'))->toBe(3000)
        ->and($response->json('tags'))->toContain('Laravel');
});

test('creating a job accepts a roles array', function () {
    [, $token] = employerWithCompany();

    $roles = $this->withToken($token)
        ->postJson('/api/employer/jobs', jobPayload(['roles' => ['Frontend', 'React']]))
        ->assertCreated()
        ->json('roles');

    expect($roles)->toContain('Frontend')->toContain('React');
});

// ── Employer: PUT /api/employer/jobs/{id} ────────────────────────────────

test('an employer can partially update their job post', function () {
    [$employer, $token] = userWithToken('employer');
    $job = createJob($employer);

    $this->withToken($token)
        ->putJson("/api/employer/jobs/{$job->_id}", ['title' => 'Updated Title', 'city' => 'Dubai'])
        ->assertOk()
        ->assertJsonPath('title', 'Updated Title')
        ->assertJsonPath('city', 'Dubai');
});

test('an employer can update the roles on their job post', function () {
    [$employer, $token] = userWithToken('employer');
    $job = createJob($employer, ['roles' => ['Backend']]);

    $this->withToken($token)
        ->putJson("/api/employer/jobs/{$job->_id}", ['roles' => ['Backend', 'Node.js']])
        ->assertOk()
        ->assertJsonPath('roles.0', 'Backend')
        ->assertJsonPath('roles.1', 'Node.js');
});

test('updating a non-existent job post returns 404', function () {
    [, $token] = userWithToken('employer');

    $this->withToken($token)
        ->putJson('/api/employer/jobs/000000000000000000000000', ['title' => 'Ghost'])
        ->assertNotFound();
});

test('an employer cannot update another employers job post', function () {
    $job = createJob(createUser('employer'));
    $otherToken = tokenFor('employer');

    $this->withToken($otherToken)
        ->putJson("/api/employer/jobs/{$job->_id}", ['title' => 'Hijacked'])
        ->assertForbidden();
});

// ── Employer: DELETE /api/employer/jobs/{id} ─────────────────────────────

test('deleting a non-existent job post returns 404', function () {
    [, $token] = userWithToken('employer');

    $this->withToken($token)
        ->deleteJson('/api/employer/jobs/000000000000000000000000')
        ->assertNotFound();
});

test('an employer cannot delete another employers job post', function () {
    $job = createJob(createUser('employer'));
    $otherToken = tokenFor('employer');

    $this->withToken($otherToken)
        ->deleteJson("/api/employer/jobs/{$job->_id}")
        ->assertForbidden();
});

// ── Employer: POST /api/employer/jobs/{id}/deactivate ────────────────────

test('deactivating a non-existent job post returns 404', function () {
    [, $token] = userWithToken('employer');

    $this->withToken($token)
        ->postJson('/api/employer/jobs/000000000000000000000000/deactivate')
        ->assertNotFound();
});

test('an employer cannot deactivate another employers job post', function () {
    $job = createJob(createUser('employer'));
    $otherToken = tokenFor('employer');

    $this->withToken($otherToken)
        ->postJson("/api/employer/jobs/{$job->_id}/deactivate")
        ->assertForbidden();
});

// ── Employer: GET /api/employer/jobs ─────────────────────────────────────

test('my posts returns only the authenticated employers posts', function () {
    [$employer, $token] = userWithToken('employer');
    createJob($employer, ['title' => 'My Job']);
    createJob(createUser('employer'), ['title' => 'Their Job']);

    $titles = collect(
        $this->withToken($token)->getJson('/api/employer/jobs')->assertOk()->json()
    )->pluck('title');

    expect($titles)->toContain('My Job')->not->toContain('Their Job');
});

test('my posts includes an application_count for each post', function () {
    [$employer, $token] = userWithToken('employer');
    $job = createJob($employer, ['title' => 'Count Job']);
    $seeker = createUser('employee');
    Application::create([
        'user_id'     => (string) $seeker->_id,
        'job_post_id' => (string) $job->_id,
        'status'      => 'pending',
        'applied_at'  => now(),
    ]);

    $post = collect(
        $this->withToken($token)->getJson('/api/employer/jobs')->assertOk()->json()
    )->firstWhere('title', 'Count Job');

    expect($post)->not->toBeNull()
        ->and($post['application_count'])->toBe(1);
});

// ── Auth guards ──────────────────────────────────────────────────────────

test('an unauthenticated user cannot create a job post', function () {
    $this->postJson('/api/employer/jobs', ['title' => 'Sneaky'])->assertUnauthorized();
});

test('a job seeker cannot create a job post', function () {
    $this->withToken(tokenFor('employee'))
        ->postJson('/api/employer/jobs', jobPayload(['title' => 'Sneaky']))
        ->assertForbidden();
});
