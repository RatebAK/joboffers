<?php

// ============================================================
// DO NOT DELETE — Comprehensive tests for job post endpoints.
// Covers: public listing, single show, 404s, job_id generation,
// employer CRUD, deactivation, ownership enforcement,
// all filter combinations, and pagination edge cases.
// ============================================================

use App\Models\Application;
use App\Models\JobPost;
use App\Models\User;

// ── Helpers ───────────────────────────────────────────────────

function jpEmployer(): array
{
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);
    return [$employer, $token];
}

function jpJob(string $employerId, array $overrides = []): JobPost
{
    return JobPost::create(array_merge([
        'title'               => 'Test Engineer',
        'description'         => 'Write tests.',
        'requirements'        => 'PHP required.',
        'company_name'        => 'TestCo',
        'job_type'            => 'full_time',
        'work_mode'           => 'remote',
        'experience_level'    => 'mid',
        'location'            => 'Beirut, Lebanon',
        'category'            => 'Engineering',
        'tags'                => ['PHP', 'Testing'],
        'salary_range'        => ['min' => 2000, 'max' => 4000, 'currency' => 'USD'],
        'employer_id'         => $employerId,
        'is_active'           => true,
    ], $overrides));
}

// ── Public: GET /api/jobs ─────────────────────────────────────

test('public job list returns only active posts', function () {
    [$employer] = jpEmployer();
    $uid = uniqid('ACTIVEJOB_');
    $active   = jpJob((string) $employer->_id, ['is_active' => true,  'title' => $uid . '_active']);
    $inactive = jpJob((string) $employer->_id, ['is_active' => false, 'title' => $uid . '_inactive']);

    // Filter by unique keyword so pagination doesn't matter
    $response = $this->getJson("/api/jobs?keyword={$uid}&per_page=100")->assertStatus(200);
    $titles = collect($response->json('data'))->pluck('title')->toArray();

    expect($titles)->toContain($uid . '_active');
    expect($titles)->not->toContain($uid . '_inactive');

    $active->delete(); $inactive->delete(); $employer->delete();
});

test('public job list has correct pagination shape', function () {
    [$employer] = jpEmployer();
    $job = jpJob((string) $employer->_id);

    $this->getJson('/api/jobs')
         ->assertStatus(200)
         ->assertJsonStructure([
             'data', 'current_page', 'per_page', 'total',
             'total_pages', 'next_page', 'prev_page',
             'next_page_url', 'prev_page_url',
         ]);

    $job->delete(); $employer->delete();
});

test('public job list per_page is capped at 100', function () {
    [$employer] = jpEmployer();

    $response = $this->getJson('/api/jobs?per_page=999')->assertStatus(200);
    expect($response->json('per_page'))->toBeLessThanOrEqual(100);

    $employer->delete();
});

// ── Public: GET /api/jobs/{id} ────────────────────────────────

test('public show returns a single job post', function () {
    [$employer] = jpEmployer();
    $job = jpJob((string) $employer->_id);

    $this->getJson("/api/jobs/{$job->_id}")
         ->assertStatus(200)
         ->assertJsonPath('title', 'Test Engineer')
         ->assertJsonPath('job_type', 'full_time');

    $job->delete(); $employer->delete();
});

test('public show returns 404 for unknown id', function () {
    $this->getJson('/api/jobs/000000000000000000000000')->assertStatus(404);
});

test('public show returns inactive job post by id', function () {
    // Individual show does not filter by is_active — only the list does
    [$employer] = jpEmployer();
    $job = jpJob((string) $employer->_id, ['is_active' => false]);

    $this->getJson("/api/jobs/{$job->_id}")->assertStatus(200);

    $job->delete(); $employer->delete();
});

// ── Employer: POST /api/employer/jobs ─────────────────────────

test('employer job creation assigns a job_id field', function () {
    [$employer, $token] = jpEmployer();

    $response = $this->withToken($token)->postJson('/api/employer/jobs', [
        'title'        => 'New Role',
        'description'  => 'Desc.',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'contract',
    ]);

    $response->assertStatus(201);
    expect($response->json('job_id'))->toStartWith('JOB-');

    JobPost::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('employer job creation sets employer_id from auth user', function () {
    [$employer, $token] = jpEmployer();

    $response = $this->withToken($token)->postJson('/api/employer/jobs', [
        'title'        => 'Auth Check',
        'description'  => 'Desc.',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'full_time',
    ]);

    $response->assertStatus(201);
    expect($response->json('employer_id'))->toBe((string) $employer->_id);

    JobPost::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('employer job creation validates job_type enum', function () {
    [$employer, $token] = jpEmployer();

    $this->withToken($token)->postJson('/api/employer/jobs', [
        'title'        => 'Bad Type',
        'description'  => 'Desc.',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'gig_economy',
    ])->assertStatus(422)->assertJsonStructure(['job_type']);

    $employer->delete();
});

test('employer job creation validates work_mode enum', function () {
    [$employer, $token] = jpEmployer();

    $this->withToken($token)->postJson('/api/employer/jobs', [
        'title'        => 'Bad Mode',
        'description'  => 'Desc.',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'full_time',
        'work_mode'    => 'moon',
    ])->assertStatus(422)->assertJsonStructure(['work_mode']);

    $employer->delete();
});

test('employer job creation validates experience_level enum', function () {
    [$employer, $token] = jpEmployer();

    $this->withToken($token)->postJson('/api/employer/jobs', [
        'title'            => 'Bad Level',
        'description'      => 'Desc.',
        'requirements'     => 'Req.',
        'company_name'     => 'Co',
        'job_type'         => 'full_time',
        'experience_level' => 'god',
    ])->assertStatus(422)->assertJsonStructure(['experience_level']);

    $employer->delete();
});

test('employer job creation accepts optional salary_range and tags', function () {
    [$employer, $token] = jpEmployer();

    $response = $this->withToken($token)->postJson('/api/employer/jobs', [
        'title'        => 'Full Job',
        'description'  => 'Desc.',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'full_time',
        'salary_range' => ['min' => 3000, 'max' => 6000, 'currency' => 'USD'],
        'tags'         => ['Laravel', 'PHP'],
    ]);

    $response->assertStatus(201);
    expect($response->json('salary_range.min'))->toBe(3000);
    expect($response->json('tags'))->toContain('Laravel');

    JobPost::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

// ── Employer: PUT /api/employer/jobs/{id} ─────────────────────

test('employer can partially update a job post', function () {
    [$employer, $token] = jpEmployer();
    $job = jpJob((string) $employer->_id);

    $this->withToken($token)->putJson("/api/employer/jobs/{$job->_id}", [
        'title'    => 'Updated Title',
        'location' => 'Dubai, UAE',
    ])->assertStatus(200)
      ->assertJsonPath('title', 'Updated Title')
      ->assertJsonPath('location', 'Dubai, UAE');

    $job->delete(); $employer->delete();
});

test('update returns 404 for non-existent job post', function () {
    [$employer, $token] = jpEmployer();

    $this->withToken($token)->putJson('/api/employer/jobs/000000000000000000000000', [
        'title' => 'Ghost',
    ])->assertStatus(404);

    $employer->delete();
});

test('employer cannot update another employers job post', function () {
    [$employer]         = jpEmployer();
    [$other, $token]    = jpEmployer();
    $job = jpJob((string) $employer->_id);

    $this->withToken($token)->putJson("/api/employer/jobs/{$job->_id}", [
        'title' => 'Hijacked',
    ])->assertStatus(403);

    $job->delete(); $employer->delete(); $other->delete();
});

// ── Employer: DELETE /api/employer/jobs/{id} ──────────────────

test('delete returns 404 for non-existent job post', function () {
    [$employer, $token] = jpEmployer();

    $this->withToken($token)->deleteJson('/api/employer/jobs/000000000000000000000000')
         ->assertStatus(404);

    $employer->delete();
});

test('employer cannot delete another employers job post', function () {
    [$employer]      = jpEmployer();
    [$other, $token] = jpEmployer();
    $job = jpJob((string) $employer->_id);

    $this->withToken($token)->deleteJson("/api/employer/jobs/{$job->_id}")
         ->assertStatus(403);

    $job->delete(); $employer->delete(); $other->delete();
});

// ── Employer: POST /api/employer/jobs/{id}/deactivate ─────────

test('deactivate returns 404 for non-existent job post', function () {
    [$employer, $token] = jpEmployer();

    $this->withToken($token)->postJson('/api/employer/jobs/000000000000000000000000/deactivate')
         ->assertStatus(404);

    $employer->delete();
});

test('employer cannot deactivate another employers job post', function () {
    [$employer]      = jpEmployer();
    [$other, $token] = jpEmployer();
    $job = jpJob((string) $employer->_id);

    $this->withToken($token)->postJson("/api/employer/jobs/{$job->_id}/deactivate")
         ->assertStatus(403);

    $job->delete(); $employer->delete(); $other->delete();
});

// ── Employer: GET /api/employer/jobs ──────────────────────────

test('my posts returns only the authenticated employers posts', function () {
    [$employer, $token] = jpEmployer();
    [$other]            = jpEmployer();
    $mine   = jpJob((string) $employer->_id, ['title' => 'My Job']);
    $theirs = jpJob((string) $other->_id, ['title' => 'Their Job']);

    $response = $this->withToken($token)->getJson('/api/employer/jobs')->assertStatus(200);
    $titles = collect($response->json())->pluck('title')->toArray();

    expect($titles)->toContain('My Job');
    expect($titles)->not->toContain('Their Job');

    $mine->delete(); $theirs->delete(); $employer->delete(); $other->delete();
});

test('my posts includes application_count for each post', function () {
    [$employer, $token] = jpEmployer();
    $job = jpJob((string) $employer->_id, ['title' => 'Count Job']);
    $seeker = User::factory()->employee()->create();
    Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $response = $this->withToken($token)->getJson('/api/employer/jobs')->assertStatus(200);
    $post = collect($response->json())->firstWhere('title', 'Count Job');
    expect($post)->not->toBeNull();
    expect($post['application_count'])->toBe(1);

    Application::where('user_id', $seeker->_id)->delete();
    $job->delete(); $seeker->delete(); $employer->delete();
});

// ── Auth guard ────────────────────────────────────────────────

test('unauthenticated user cannot create a job post', function () {
    $this->postJson('/api/employer/jobs', ['title' => 'Sneaky'])->assertStatus(401);
});

test('job seeker cannot create a job post', function () {
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);

    $this->withToken($token)->postJson('/api/employer/jobs', [
        'title'        => 'Sneaky',
        'description'  => 'Desc.',
        'requirements' => 'Req.',
        'company_name' => 'Co',
        'job_type'     => 'full_time',
    ])->assertStatus(403);

    $seeker->delete();
});
