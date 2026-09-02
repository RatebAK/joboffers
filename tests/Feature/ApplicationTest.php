<?php

// =============================================================================
// ApplicationTest
//
// Covers the application flow: apply validation, duplicate prevention, inactive
// job guard, withdraw rules, employer listing with applicant data, status
// update lifecycle, ownership enforcement, and 404s.
// =============================================================================

use App\Models\Application;
use App\Models\JobSeekerProfile;

/** Persist a pending application for the given seeker + job. */
function application(string $userId, string $jobId, array $attributes = []): Application
{
    return Application::create(array_merge([
        'user_id'     => $userId,
        'job_post_id' => $jobId,
        'status'      => 'pending',
        'applied_at'  => now(),
    ], $attributes));
}

beforeEach(function () {
    [$this->employer, $this->employerToken] = userWithToken('employer');
    [$this->seeker, $this->seekerToken]     = userWithToken('employee');
    $this->job = createJob($this->employer, ['title' => 'App Test Job']);
});

// ── Apply: POST /api/job-seeker/apply ────────────────────────────────────

test('applying requires a job_post_id', function () {
    $this->withToken($this->seekerToken)
        ->postJson('/api/job-seeker/apply', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('job_post_id');
});

test('applying to a non-existent job post returns 404', function () {
    $this->withToken($this->seekerToken)
        ->postJson('/api/job-seeker/apply', ['job_post_id' => '000000000000000000000000'])
        ->assertNotFound();
});

test('applying to an inactive job post returns 404', function () {
    $inactive = createJob($this->employer, ['is_active' => false]);

    $this->withToken($this->seekerToken)
        ->postJson('/api/job-seeker/apply', ['job_post_id' => (string) $inactive->_id])
        ->assertNotFound();
});

test('applying creates an application with pending status', function () {
    $this->withToken($this->seekerToken)
        ->postJson('/api/job-seeker/apply', [
            'job_post_id'  => (string) $this->job->_id,
            'cover_letter' => 'I am a great fit.',
        ])
        ->assertCreated()
        ->assertJsonPath('application.status', 'pending')
        ->assertJsonStructure(['message', 'application' => ['user_id', 'job_post_id', 'status', 'applied_at']]);
});

test('applying stores the cover letter', function () {
    $this->withToken($this->seekerToken)
        ->postJson('/api/job-seeker/apply', [
            'job_post_id'  => (string) $this->job->_id,
            'cover_letter' => 'My unique cover letter.',
        ])
        ->assertCreated();

    expect(Application::where('user_id', $this->seeker->_id)->first()->cover_letter)
        ->toBe('My unique cover letter.');
});

test('applying twice to the same job returns 409', function () {
    application((string) $this->seeker->_id, (string) $this->job->_id);

    $this->withToken($this->seekerToken)
        ->postJson('/api/job-seeker/apply', ['job_post_id' => (string) $this->job->_id])
        ->assertStatus(409);
});

test('the cover letter has a maximum length', function () {
    $this->withToken($this->seekerToken)
        ->postJson('/api/job-seeker/apply', [
            'job_post_id'  => (string) $this->job->_id,
            'cover_letter' => str_repeat('a', 1001),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('cover_letter');
});

// ── List: GET /api/job-seeker/applications ───────────────────────────────

test('a seeker can list their own applications', function () {
    application((string) $this->seeker->_id, (string) $this->job->_id);

    $this->withToken($this->seekerToken)
        ->getJson('/api/job-seeker/applications')
        ->assertOk()
        ->assertJsonStructure(['applications']);
});

test('a seeker only sees their own applications', function () {
    $other = createUser('employee');
    application((string) $this->seeker->_id, (string) $this->job->_id);
    application((string) $other->_id, (string) $this->job->_id);

    $userIds = collect(
        $this->withToken($this->seekerToken)
            ->getJson('/api/job-seeker/applications')
            ->assertOk()
            ->json('applications.data')
    )->pluck('user_id')->unique();

    expect($userIds->all())->toBe([(string) $this->seeker->_id]);
});

// ── Withdraw: DELETE /api/job-seeker/applications/{id}/withdraw ───────────

test('a seeker can withdraw a pending application', function () {
    $application = application((string) $this->seeker->_id, (string) $this->job->_id);

    $this->withToken($this->seekerToken)
        ->deleteJson("/api/job-seeker/applications/{$application->_id}/withdraw")
        ->assertOk();

    expect(Application::find($application->_id))->toBeNull();
});

test('a seeker cannot withdraw an accepted application', function () {
    $application = application((string) $this->seeker->_id, (string) $this->job->_id, ['status' => 'accepted']);

    $this->withToken($this->seekerToken)
        ->deleteJson("/api/job-seeker/applications/{$application->_id}/withdraw")
        ->assertForbidden();
});

test('a seeker cannot withdraw a rejected application', function () {
    $application = application((string) $this->seeker->_id, (string) $this->job->_id, ['status' => 'rejected']);

    $this->withToken($this->seekerToken)
        ->deleteJson("/api/job-seeker/applications/{$application->_id}/withdraw")
        ->assertForbidden();
});

test('withdrawing a non-existent application returns 404', function () {
    $this->withToken($this->seekerToken)
        ->deleteJson('/api/job-seeker/applications/000000000000000000000000/withdraw')
        ->assertNotFound();
});

test('a seeker cannot withdraw another seekers application', function () {
    $application = application((string) $this->seeker->_id, (string) $this->job->_id);
    $otherToken  = tokenFor('employee');

    // Scoped by user_id, so it appears as not found rather than forbidden.
    $this->withToken($otherToken)
        ->deleteJson("/api/job-seeker/applications/{$application->_id}/withdraw")
        ->assertNotFound();
});

// ── Employer: GET /api/employer/jobs/{jobId}/applications ─────────────────

test('an employer can list applications for their job post', function () {
    application((string) $this->seeker->_id, (string) $this->job->_id);

    $this->withToken($this->employerToken)
        ->getJson("/api/employer/jobs/{$this->job->_id}/applications")
        ->assertOk()
        ->assertJsonStructure(['applications']);
});

test('the employer applications list includes applicant name and ATS score', function () {
    JobSeekerProfile::create(['user_id' => (string) $this->seeker->_id, 'ats_score' => 78]);
    application((string) $this->seeker->_id, (string) $this->job->_id);

    $items = $this->withToken($this->employerToken)
        ->getJson("/api/employer/jobs/{$this->job->_id}/applications")
        ->assertOk()
        ->json('applications.data');

    expect($items)->not->toBeEmpty()
        ->and($items[0])->toHaveKeys(['applicant_name', 'ats_score']);
});

test('an employer cannot list applications for another employers job post', function () {
    $otherToken = tokenFor('employer');

    $this->withToken($otherToken)
        ->getJson("/api/employer/jobs/{$this->job->_id}/applications")
        ->assertForbidden();
});

test('listing applications for a non-existent job post returns 404', function () {
    $this->withToken($this->employerToken)
        ->getJson('/api/employer/jobs/000000000000000000000000/applications')
        ->assertNotFound();
});

// ── Employer: PUT /api/employer/applications/{id}/status ─────────────────

test('an employer can set an application to any valid status', function () {
    foreach (['reviewed', 'accepted', 'rejected', 'pending'] as $status) {
        $application = application((string) $this->seeker->_id, (string) $this->job->_id);

        $this->withToken($this->employerToken)
            ->putJson("/api/employer/applications/{$application->_id}/status", ['status' => $status])
            ->assertOk()
            ->assertJsonPath('application.status', $status);
    }
});

test('an employer can add feedback when updating status', function () {
    $application = application((string) $this->seeker->_id, (string) $this->job->_id);

    $this->withToken($this->employerToken)
        ->putJson("/api/employer/applications/{$application->_id}/status", [
            'status'   => 'reviewed',
            'feedback' => 'Strong candidate, moving forward.',
        ])
        ->assertOk();

    expect(Application::find($application->_id)->feedback)->toBe('Strong candidate, moving forward.');
});

test('updating status rejects an invalid status value', function () {
    $application = application((string) $this->seeker->_id, (string) $this->job->_id);

    $this->withToken($this->employerToken)
        ->putJson("/api/employer/applications/{$application->_id}/status", ['status' => 'hired'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('status');
});

test('updating status on a non-existent application returns 404', function () {
    $this->withToken($this->employerToken)
        ->putJson('/api/employer/applications/000000000000000000000000/status', ['status' => 'reviewed'])
        ->assertNotFound();
});

test('an employer cannot update status on another employers application', function () {
    $application = application((string) $this->seeker->_id, (string) $this->job->_id);
    $otherToken  = tokenFor('employer');

    $this->withToken($otherToken)
        ->putJson("/api/employer/applications/{$application->_id}/status", ['status' => 'rejected'])
        ->assertForbidden();
});
