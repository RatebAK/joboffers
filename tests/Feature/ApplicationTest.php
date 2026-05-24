<?php

// ============================================================
// DO NOT DELETE — Comprehensive tests for the application flow.
// Covers: apply validation, duplicate prevention, inactive job
// guard, withdraw rules, employer listing with applicant data,
// status update lifecycle, ownership enforcement, and 404s.
// ============================================================

use App\Models\Application;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;

// ── Helpers ───────────────────────────────────────────────────

function appSeeker(): array
{
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);
    return [$seeker, $token];
}

function appEmployer(): array
{
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);
    return [$employer, $token];
}

function appJob(string $employerId, bool $active = true): JobPost
{
    return JobPost::create([
        'title'        => 'App Test Job',
        'description'  => 'Test.',
        'requirements' => 'PHP.',
        'company_name' => 'AppCo',
        'job_type'     => 'full_time',
        'location'     => 'Remote',
        'employer_id'  => $employerId,
        'is_active'    => $active,
    ]);
}

// ── Apply: POST /api/job-seeker/apply ─────────────────────────

test('apply requires job_post_id', function () {
    [, $token] = appSeeker();

    $this->withToken($token)->postJson('/api/job-seeker/apply', [])
         ->assertStatus(422)
         ->assertJsonStructure(['errors' => ['job_post_id']]);
});

test('apply returns 404 for non-existent job post', function () {
    [, $token] = appSeeker();

    $this->withToken($token)->postJson('/api/job-seeker/apply', [
        'job_post_id' => '000000000000000000000000',
    ])->assertStatus(404);
});

test('apply returns 404 for inactive job post', function () {
    [$employer] = appEmployer();
    $job = appJob((string) $employer->_id, false);
    [, $token] = appSeeker();

    $this->withToken($token)->postJson('/api/job-seeker/apply', [
        'job_post_id' => (string) $job->_id,
    ])->assertStatus(404);

    $job->delete(); $employer->delete();
});

test('apply creates application with pending status', function () {
    [$employer] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker, $token] = appSeeker();

    $response = $this->withToken($token)->postJson('/api/job-seeker/apply', [
        'job_post_id'  => (string) $job->_id,
        'cover_letter' => 'I am a great fit.',
    ]);

    $response->assertStatus(201)
             ->assertJsonPath('application.status', 'pending')
             ->assertJsonStructure(['message', 'application' => ['user_id', 'job_post_id', 'status', 'applied_at']]);

    Application::where('user_id', $seeker->_id)->delete();
    $job->delete(); $seeker->delete(); $employer->delete();
});

test('apply stores cover_letter', function () {
    [$employer] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker, $token] = appSeeker();

    $this->withToken($token)->postJson('/api/job-seeker/apply', [
        'job_post_id'  => (string) $job->_id,
        'cover_letter' => 'My unique cover letter.',
    ])->assertStatus(201);

    $app = Application::where('user_id', $seeker->_id)->first();
    expect($app->cover_letter)->toBe('My unique cover letter.');

    Application::where('user_id', $seeker->_id)->delete();
    $job->delete(); $seeker->delete(); $employer->delete();
});

test('apply prevents duplicate application with 409', function () {
    [$employer] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker, $token] = appSeeker();
    Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($token)->postJson('/api/job-seeker/apply', [
        'job_post_id' => (string) $job->_id,
    ])->assertStatus(409);

    Application::where('user_id', $seeker->_id)->delete();
    $job->delete(); $seeker->delete(); $employer->delete();
});

test('cover_letter max length is enforced', function () {
    [$employer] = appEmployer();
    $job = appJob((string) $employer->_id);
    [, $token] = appSeeker();

    $this->withToken($token)->postJson('/api/job-seeker/apply', [
        'job_post_id'  => (string) $job->_id,
        'cover_letter' => str_repeat('a', 1001),
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['cover_letter']]);

    $job->delete(); $employer->delete();
});

// ── List: GET /api/job-seeker/applications ────────────────────

test('seeker can list their own applications', function () {
    [$employer] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker, $token] = appSeeker();
    Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($token)->getJson('/api/job-seeker/applications')
         ->assertStatus(200)
         ->assertJsonStructure(['applications']);

    Application::where('user_id', $seeker->_id)->delete();
    $job->delete(); $seeker->delete(); $employer->delete();
});

test('seeker only sees their own applications', function () {
    [$employer] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker, $token] = appSeeker();
    [$other]          = appSeeker();
    Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);
    Application::create(['user_id' => $other->_id,  'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $response = $this->withToken($token)->getJson('/api/job-seeker/applications')->assertStatus(200);
    $userIds = collect($response->json('applications.data'))->pluck('user_id')->unique()->toArray();
    expect($userIds)->toHaveCount(1);
    expect($userIds[0])->toBe((string) $seeker->_id);

    Application::where('user_id', $seeker->_id)->delete();
    Application::where('user_id', $other->_id)->delete();
    $job->delete(); $seeker->delete(); $other->delete(); $employer->delete();
});

// ── Withdraw: DELETE /api/job-seeker/applications/{id}/withdraw

test('seeker can withdraw a pending application', function () {
    [$employer] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker, $token] = appSeeker();
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($token)->deleteJson("/api/job-seeker/applications/{$app->_id}/withdraw")
         ->assertStatus(200);

    expect(Application::find($app->_id))->toBeNull();

    $job->delete(); $seeker->delete(); $employer->delete();
});

test('seeker cannot withdraw an accepted application', function () {
    [$employer] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker, $token] = appSeeker();
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'accepted', 'applied_at' => now()]);

    $this->withToken($token)->deleteJson("/api/job-seeker/applications/{$app->_id}/withdraw")
         ->assertStatus(403);

    $app->delete(); $job->delete(); $seeker->delete(); $employer->delete();
});

test('seeker cannot withdraw a rejected application', function () {
    [$employer] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker, $token] = appSeeker();
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'rejected', 'applied_at' => now()]);

    $this->withToken($token)->deleteJson("/api/job-seeker/applications/{$app->_id}/withdraw")
         ->assertStatus(403);

    $app->delete(); $job->delete(); $seeker->delete(); $employer->delete();
});

test('withdraw returns 404 for non-existent application', function () {
    [, $token] = appSeeker();

    $this->withToken($token)->deleteJson('/api/job-seeker/applications/000000000000000000000000/withdraw')
         ->assertStatus(404);
});

test('seeker cannot withdraw another seekers application', function () {
    [$employer] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker]         = appSeeker();
    [, $token]        = appSeeker();
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($token)->deleteJson("/api/job-seeker/applications/{$app->_id}/withdraw")
         ->assertStatus(404); // filtered by user_id so it's a 404 not 403

    $app->delete(); $job->delete(); $seeker->delete(); $employer->delete();
});

// ── Employer: GET /api/employer/jobs/{jobId}/applications ─────

test('employer can list applications for their job post', function () {
    [$employer, $token] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker] = appSeeker();
    Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($token)->getJson("/api/employer/jobs/{$job->_id}/applications")
         ->assertStatus(200)
         ->assertJsonStructure(['applications']);

    Application::where('user_id', $seeker->_id)->delete();
    $job->delete(); $seeker->delete(); $employer->delete();
});

test('employer applications list includes applicant_name and ats_score', function () {
    [$employer, $token] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker] = appSeeker();
    JobSeekerProfile::create(['user_id' => $seeker->_id, 'ats_score' => 78]);
    Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $response = $this->withToken($token)->getJson("/api/employer/jobs/{$job->_id}/applications")
                     ->assertStatus(200);

    $items = $response->json('applications.data');
    expect($items)->not->toBeEmpty();
    expect($items[0])->toHaveKey('applicant_name');
    expect($items[0])->toHaveKey('ats_score');

    Application::where('user_id', $seeker->_id)->delete();
    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $job->delete(); $seeker->delete(); $employer->delete();
});

test('employer cannot list applications for another employers job post', function () {
    [$employer]      = appEmployer();
    [$other, $token] = appEmployer();
    $job = appJob((string) $employer->_id);

    $this->withToken($token)->getJson("/api/employer/jobs/{$job->_id}/applications")
         ->assertStatus(403);

    $job->delete(); $employer->delete(); $other->delete();
});

test('employer applications list returns 404 for non-existent job post', function () {
    [, $token] = appEmployer();

    $this->withToken($token)->getJson('/api/employer/jobs/000000000000000000000000/applications')
         ->assertStatus(404);
});

// ── Employer: PUT /api/employer/applications/{id}/status ──────

test('employer can update application status to all valid values', function () {
    [$employer, $token] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker] = appSeeker();

    foreach (['reviewed', 'accepted', 'rejected', 'pending'] as $status) {
        $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

        $this->withToken($token)->putJson("/api/employer/applications/{$app->_id}/status", [
            'status' => $status,
        ])->assertStatus(200)->assertJsonPath('application.status', $status);

        $app->delete();
    }

    $job->delete(); $seeker->delete(); $employer->delete();
});

test('employer can add feedback when updating status', function () {
    [$employer, $token] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker] = appSeeker();
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($token)->putJson("/api/employer/applications/{$app->_id}/status", [
        'status'   => 'reviewed',
        'feedback' => 'Strong candidate, moving forward.',
    ])->assertStatus(200);

    expect(Application::find($app->_id)->feedback)->toBe('Strong candidate, moving forward.');

    $app->delete(); $job->delete(); $seeker->delete(); $employer->delete();
});

test('status update rejects invalid status value', function () {
    [$employer, $token] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker] = appSeeker();
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($token)->putJson("/api/employer/applications/{$app->_id}/status", [
        'status' => 'hired',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['status']]);

    $app->delete(); $job->delete(); $seeker->delete(); $employer->delete();
});

test('status update returns 404 for non-existent application', function () {
    [, $token] = appEmployer();

    $this->withToken($token)->putJson('/api/employer/applications/000000000000000000000000/status', [
        'status' => 'reviewed',
    ])->assertStatus(404);
});

test('employer cannot update status for another employers application', function () {
    [$employer]      = appEmployer();
    [$other, $token] = appEmployer();
    $job = appJob((string) $employer->_id);
    [$seeker] = appSeeker();
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($token)->putJson("/api/employer/applications/{$app->_id}/status", [
        'status' => 'rejected',
    ])->assertStatus(403);

    $app->delete(); $job->delete(); $seeker->delete(); $employer->delete(); $other->delete();
});
