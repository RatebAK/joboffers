<?php

// Regression tests for three fixed bugs:
//   1. GET /api/jobs/search returning 404 (route order vs jobs/{id})
//   2. POST /api/job-seeker/coach/sessions missing route
//   3. POST /api/job-seeker/apply smoke checks

use App\Models\Application;

// ── 1. Public job search route order fix ───────────────────────────

test('GET /api/jobs/search does not return 404', function () {
    $this->getJson('/api/jobs/search')->assertOk();
});

test('jobs/search does not match the jobs/{id} route', function () {
    // Before the fix, "search" was treated as the {id} param and returned a 404
    // from the show() controller.
    $this->getJson('/api/jobs/search')
        ->assertOk()
        ->assertJsonStructure(['jobs']);
});

// ── 2. POST coach/sessions now exists ──────────────────────────────

test('POST /api/job-seeker/coach/sessions returns 201', function () {
    $this->withToken(tokenFor('employee'))
        ->postJson('/api/job-seeker/coach/sessions', ['title' => 'Quick test session'])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Quick test session');
});

test('POST coach/sessions defaults title to New Session', function () {
    $this->withToken(tokenFor('employee'))
        ->postJson('/api/job-seeker/coach/sessions')
        ->assertCreated()
        ->assertJsonPath('data.title', 'New Session');
});

test('POST coach/sessions requires auth', function () {
    $this->postJson('/api/job-seeker/coach/sessions')->assertUnauthorized();
});

// ── 3. POST job-seeker/apply smoke checks ──────────────────────────

test('POST /api/job-seeker/apply returns 422 without job_post_id', function () {
    $this->withToken(tokenFor('employee'))
        ->postJson('/api/job-seeker/apply', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['job_post_id']]);
});

test('POST /api/job-seeker/apply returns 404 for non-existent job', function () {
    $this->withToken(tokenFor('employee'))
        ->postJson('/api/job-seeker/apply', ['job_post_id' => '000000000000000000000000'])
        ->assertNotFound();
});

test('POST /api/job-seeker/apply creates application for active job', function () {
    $employer = createUser('employer');
    $job      = createJob($employer, ['title' => 'Regression Test Job']);

    [$seeker, $token] = userWithToken('employee');

    $this->withToken($token)
        ->postJson('/api/job-seeker/apply', ['job_post_id' => (string) $job->_id])
        ->assertCreated()
        ->assertJsonPath('application.status', 'pending');

    expect(Application::where('user_id', $seeker->_id)->where('job_post_id', $job->_id)->exists())->toBeTrue();
});
