<?php

// Integration tests for the employer lifecycle: registration, job post CRUD,
// searching job seekers, sending direct offers, and managing applications.

use App\Models\Application;
use App\Models\DirectOffer;
use App\Models\JobPost;

// A seeker with a searchable profile (skills / ats_score / location).
function employerFlowSeeker(): array
{
    return createSeekerWithProfile([], [
        'is_actively_seeking' => true,
        'current_job_title'   => 'Frontend Developer',
        'ai_skills'           => ['Vue.js', 'JavaScript', 'CSS'],
        'ats_score'           => 75,
        'ai_location'         => 'Beirut, Lebanon',
        'ai_summary'          => 'Experienced frontend developer.',
    ]);
}

// A job post matching the employer flow expectations.
function employerFlowJob(\App\Models\User $employer): JobPost
{
    return createJob($employer, [
        'title'        => 'Vue.js Developer',
        'description'  => 'Build beautiful UIs.',
        'company_name' => 'Test Employer Co',
    ]);
}

beforeEach(function () {
    [$this->employer, $this->employerToken] = userWithToken('employer');
});

// ── Registration ───────────────────────────────────────────────────

test('employer can register with employer role', function () {
    $this->postJson('/api/auth/register', [
        'name'                  => 'New Employer',
        'email'                 => 'new_employer_'.uniqid().'@example.com',
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'role'                  => 'employer',
    ])
        ->assertCreated()
        ->assertJsonPath('user.roles.0', 'employer');
});

// ── Job post CRUD ──────────────────────────────────────────────────

test('employer can create a job post', function () {
    createCompanyFor($this->employer, ['name' => 'Test Employer Co']);

    $this->withToken($this->employerToken)->postJson('/api/employer/jobs', [
        'title'       => 'React Developer',
        'description' => 'Build React apps.',
        'vacancies'   => 1,
        'city'        => 'Beirut',
        'job_type'    => 'contract',
        'communication_method' => 'by_forsa',
    ])
        ->assertCreated()
        ->assertJsonPath('is_active', true)
        ->assertJsonPath('title', 'React Developer');
});

test('job post creation requires mandatory fields', function () {
    createCompanyFor($this->employer, ['name' => 'Test Employer Co']);

    $this->withToken($this->employerToken)
        ->postJson('/api/employer/jobs', ['title' => 'Missing Fields'])
        ->assertStatus(422);
});

test('employer can update their own job post', function () {
    $job = employerFlowJob($this->employer);

    $this->withToken($this->employerToken)
        ->putJson("/api/employer/jobs/{$job->_id}", ['title' => 'Senior Vue.js Developer'])
        ->assertOk()
        ->assertJsonPath('title', 'Senior Vue.js Developer');
});

test('employer cannot update another employers job post', function () {
    $job = employerFlowJob($this->employer);

    $this->withToken(tokenFor('employer'))
        ->putJson("/api/employer/jobs/{$job->_id}", ['title' => 'Hijacked'])
        ->assertForbidden();
});

test('employer can deactivate a job post', function () {
    $job = employerFlowJob($this->employer);

    $this->withToken($this->employerToken)->postJson("/api/employer/jobs/{$job->_id}/deactivate")->assertOk();

    expect((bool) JobPost::find($job->_id)->is_active)->toBeFalse();
});

test('deactivated job post does not appear in public search', function () {
    $job = employerFlowJob($this->employer);
    $job->update(['is_active' => false]);

    $response = $this->getJson('/api/jobs')->assertOk();

    $ids = collect($response->json('data'))->pluck('_id')->toArray();
    expect($ids)->not->toContain((string) $job->_id);
});

test('employer can list their own job posts with application counts', function () {
    employerFlowJob($this->employer);

    $response = $this->withToken($this->employerToken)->getJson('/api/employer/jobs')->assertOk();

    foreach ($response->json() as $post) {
        expect($post)->toHaveKey('application_count');
    }
});

test('employer can delete their own job post', function () {
    $job = employerFlowJob($this->employer);

    $this->withToken($this->employerToken)->deleteJson("/api/employer/jobs/{$job->_id}")->assertOk();

    expect(JobPost::find($job->_id))->toBeNull();
});

// ── Searching job seekers ──────────────────────────────────────────

test('employer can search actively seeking job seekers', function () {
    employerFlowSeeker();

    $this->withToken($this->employerToken)->getJson('/api/employer/seekers')
        ->assertOk()
        ->assertJsonStructure(['seekers']);
});

test('employer can filter seekers by skills', function () {
    employerFlowSeeker();

    $response = $this->withToken($this->employerToken)->getJson('/api/employer/seekers?skills=Vue.js')->assertOk();

    foreach ($response->json('seekers.data') as $s) {
        $skills = array_map('strtolower', $s['ai_skills'] ?? []);
        expect($skills)->toContain('vue.js');
    }
});

test('employer can filter seekers by min ats score', function () {
    employerFlowSeeker();

    $response = $this->withToken($this->employerToken)->getJson('/api/employer/seekers?min_ats_score=70')->assertOk();

    foreach ($response->json('seekers.data') as $s) {
        expect($s['ats_score'])->toBeGreaterThanOrEqual(70);
    }
});

test('employer can view a specific seeker public profile', function () {
    [$seeker] = employerFlowSeeker();

    // The employer-facing profile is returned flat (identity fields merged with
    // the whitelisted profile fields), with sensitive AI contact fields excluded.
    $response = $this->withToken($this->employerToken)->getJson("/api/employer/seekers/{$seeker->_id}")
        ->assertOk()
        ->assertJsonStructure(['user_id', 'name', 'current_job_title', 'ats_score', 'ai_skills']);

    $body = $response->json();
    expect($body)->not->toHaveKey('ai_email');
    expect($body)->not->toHaveKey('ai_phone');
});

// ── Direct offers ──────────────────────────────────────────────────

test('employer can send a direct offer to a job seeker', function () {
    [$seeker] = employerFlowSeeker();
    $job = employerFlowJob($this->employer);

    $this->withToken($this->employerToken)->postJson('/api/employer/offers', [
        'job_seeker_id' => (string) $seeker->_id,
        'job_post_id'   => (string) $job->_id,
        'message'       => 'We think you are a great fit.',
    ])
        ->assertCreated()
        ->assertJsonPath('offer.status', 'pending');
});

test('employer cannot send duplicate direct offer', function () {
    [$seeker] = employerFlowSeeker();
    $job = employerFlowJob($this->employer);
    DirectOffer::create(['employer_id' => $this->employer->_id, 'job_seeker_id' => $seeker->_id, 'job_post_id' => $job->_id, 'message' => 'First.', 'status' => 'pending']);

    $this->withToken($this->employerToken)->postJson('/api/employer/offers', [
        'job_seeker_id' => (string) $seeker->_id, 'job_post_id' => (string) $job->_id, 'message' => 'Second.',
    ])->assertStatus(409);
});

test('employer cannot send offer for another employers job post', function () {
    [$seeker] = employerFlowSeeker();
    $otherJob = employerFlowJob(createUser('employer'));

    $this->withToken($this->employerToken)->postJson('/api/employer/offers', [
        'job_seeker_id' => (string) $seeker->_id, 'job_post_id' => (string) $otherJob->_id, 'message' => 'Sneaky.',
    ])->assertForbidden();
});

test('employer can list sent direct offers', function () {
    $this->withToken($this->employerToken)->getJson('/api/employer/offers')
        ->assertOk()
        ->assertJsonStructure(['offers']);
});

// ── Application management ─────────────────────────────────────────

test('employer can list applications for their job post', function () {
    [$seeker] = employerFlowSeeker();
    $job = employerFlowJob($this->employer);
    Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($this->employerToken)->getJson("/api/employer/jobs/{$job->_id}/applications")
        ->assertOk()
        ->assertJsonStructure(['applications']);
});

test('employer can update application status to reviewed with feedback', function () {
    [$seeker] = employerFlowSeeker();
    $job = employerFlowJob($this->employer);
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($this->employerToken)->putJson("/api/employer/applications/{$app->_id}/status", [
        'status' => 'reviewed', 'feedback' => 'Strong candidate.',
    ])->assertOk();

    $updated = Application::find($app->_id);
    expect($updated->status)->toBe('reviewed');
    expect($updated->feedback)->toBe('Strong candidate.');
});

test('employer cannot set invalid application status', function () {
    [$seeker] = employerFlowSeeker();
    $job = employerFlowJob($this->employer);
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($this->employerToken)
        ->putJson("/api/employer/applications/{$app->_id}/status", ['status' => 'hired'])
        ->assertStatus(422);
});

test('employer cannot manage applications for another employers post', function () {
    [$seeker] = employerFlowSeeker();
    $job = employerFlowJob($this->employer);
    $app = Application::create(['user_id' => $seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken(tokenFor('employer'))
        ->putJson("/api/employer/applications/{$app->_id}/status", ['status' => 'rejected'])
        ->assertForbidden();
});

// ── Authorization ──────────────────────────────────────────────────

test('job seeker cannot access employer endpoints', function () {
    $this->withToken(tokenFor('employee'))->getJson('/api/employer/jobs')->assertForbidden();
});
