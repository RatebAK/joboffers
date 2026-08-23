<?php

// ============================================================
// Regression tests for the three bugs fixed:
// 1. GET /api/jobs/search returning 404 (route order issue)
// 2. POST /api/job-seeker/coach/sessions missing route
// 3. POST /api/job-seeker/apply smoke check
// ============================================================

use App\Models\Application;
use App\Models\CoachSession;
use App\Models\JobPost;
use App\Models\User;

afterEach(function () {
    CoachSession::truncate();
});

// ── 1. Public job search route order fix ─────────────────────

test('GET /api/jobs/search does not return 404', function () {
    $this->getJson('/api/jobs/search')->assertStatus(200);
});

test('jobs/search does not match the jobs/{id} route', function () {
    // Before the fix, "search" was treated as the {id} param
    // and returned a 404 from the show() controller.
    $response = $this->getJson('/api/jobs/search');
    $response->assertStatus(200)
             ->assertJsonStructure(['jobs']);
});

// ── 2. POST coach/sessions now exists ────────────────────────

test('POST /api/job-seeker/coach/sessions returns 201', function () {
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);

    $this->withToken($token)
        ->postJson('/api/job-seeker/coach/sessions', ['title' => 'Quick test session'])
        ->assertStatus(201)
        ->assertJsonPath('data.title', 'Quick test session');

    $seeker->delete();
});

test('POST coach/sessions defaults title to New Session', function () {
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);

    $this->withToken($token)
        ->postJson('/api/job-seeker/coach/sessions')
        ->assertStatus(201)
        ->assertJsonPath('data.title', 'New Session');

    $seeker->delete();
});

test('POST coach/sessions requires auth', function () {
    $this->postJson('/api/job-seeker/coach/sessions')->assertStatus(401);
});

// ── 3. POST job-seeker/apply smoke check ─────────────────────

test('POST /api/job-seeker/apply returns 422 without job_post_id', function () {
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);

    $this->withToken($token)
        ->postJson('/api/job-seeker/apply', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['job_post_id']]);

    $seeker->delete();
});

test('POST /api/job-seeker/apply returns 404 for non-existent job', function () {
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);

    $this->withToken($token)
        ->postJson('/api/job-seeker/apply', ['job_post_id' => '000000000000000000000000'])
        ->assertStatus(404);

    $seeker->delete();
});

test('POST /api/job-seeker/apply creates application for active job', function () {
    $employer = User::factory()->employer()->create();
    $job      = JobPost::create([
        'title'        => 'Regression Test Job',
        'description'  => 'Desc',
        'requirements' => 'Req',
        'company_name' => 'TestCo',
        'job_type'     => 'full_time',
        'location'     => 'Remote',
        'employer_id'  => (string) $employer->_id,
        'is_active'    => true,
    ]);

    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);

    $this->withToken($token)
        ->postJson('/api/job-seeker/apply', ['job_post_id' => (string) $job->_id])
        ->assertStatus(201)
        ->assertJsonPath('application.status', 'pending');

    Application::where('user_id', $seeker->_id)->delete();
    $job->delete();
    $seeker->delete();
    $employer->delete();
});
