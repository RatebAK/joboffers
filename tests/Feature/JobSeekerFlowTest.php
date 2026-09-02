<?php

// Integration tests for the job seeker lifecycle: registration, login, profile
// management, browsing/searching jobs, applying and withdrawing, and handling
// direct offers.

use App\Models\Application;
use App\Models\DirectOffer;
use App\Models\JobPost;

// An employer that owns one active job — used across the apply/offer flows.
function seekerFlowEmployerWithJob(): array
{
    $employer = createUser('employer');
    $job = createJob($employer, [
        'title'        => 'PHP Developer',
        'description'  => 'Build great APIs.',
        'requirements' => 'Laravel experience required.',
        'company_name' => 'FlowCorp',
    ]);

    return [$employer, $job];
}

beforeEach(function () {
    [$this->seeker, $this->seekerToken] = userWithToken('employee');
});

// ── Registration & login ───────────────────────────────────────────

test('job seeker can register with employee role', function () {
    $this->postJson('/api/auth/register', [
        'name'                  => 'New Seeker',
        'email'                 => 'new_seeker_'.uniqid().'@example.com',
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'role'                  => 'employee',
    ])
        ->assertCreated()
        ->assertJsonPath('user.roles.0', 'employee')
        ->assertJsonStructure(['access_token', 'token_type', 'user']);
});

test('registration defaults to employee role when no role given', function () {
    $this->postJson('/api/auth/register', [
        'name'                  => 'Default Role User',
        'email'                 => 'default_role_'.uniqid().'@example.com',
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
    ])
        ->assertCreated()
        ->assertJsonPath('user.roles.0', 'employee');
});

test('job seeker can login and receive token', function () {
    [$seeker] = userWithToken('employee', ['password' => testPasswordHash('password')]);

    $this->postJson('/api/auth/login', [
        'email'    => $seeker->email,
        'password' => 'password',
    ])
        ->assertOk()
        ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user']);
});

// ── Profile ────────────────────────────────────────────────────────

test('job seeker can view their profile', function () {
    $this->withToken($this->seekerToken)->getJson('/api/job-seeker/profile')
        ->assertOk()
        ->assertJsonStructure(['profile']);
});

test('job seeker can update their profile', function () {
    $this->withToken($this->seekerToken)->putJson('/api/job-seeker/profile', [
        'current_job_title'   => 'Backend Developer',
        'expected_salary'     => 3000,
        'is_actively_seeking' => true,
        'skills' => [['name' => 'PHP'], ['name' => 'Laravel'], ['name' => 'MongoDB']],
    ])
        ->assertOk()
        ->assertJsonPath('profile.current_job_title', 'Backend Developer')
        ->assertJsonPath('profile.expected_salary', 3000);
});

test('profile update rejects invalid data', function () {
    $this->withToken($this->seekerToken)->putJson('/api/job-seeker/profile', [
        'expected_salary' => 'not-a-number',
        'linkedin_url'    => str_repeat('a', 300),
    ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors']);
});

// ── Browsing / searching ───────────────────────────────────────────

test('job seeker can browse active job posts', function () {
    $this->getJson('/api/jobs/search')
        ->assertOk()
        ->assertJsonStructure(['jobs']);
});

test('public job list returns only active posts', function () {
    [$employer] = seekerFlowEmployerWithJob();
    $inactive = createJob($employer, ['title' => 'Inactive Job', 'is_active' => false]);

    $response = $this->getJson('/api/jobs')->assertOk();

    $ids = collect($response->json('data'))->pluck('_id')->toArray();
    expect($ids)->not->toContain((string) $inactive->_id);
});

test('job seeker can filter jobs by keyword', function () {
    seekerFlowEmployerWithJob();

    $response = $this->getJson('/api/jobs/search?keyword=PHP')->assertOk();

    foreach ($response->json('jobs.data') as $j) {
        $haystack = strtolower($j['title'].' '.$j['description'].' '.($j['company_name'] ?? ''));
        expect($haystack)->toContain('php');
    }
});

// ── Applying ───────────────────────────────────────────────────────

test('job seeker can apply to an active job post', function () {
    [, $job] = seekerFlowEmployerWithJob();

    $this->withToken($this->seekerToken)->postJson('/api/job-seeker/apply', [
        'job_post_id'  => (string) $job->_id,
        'cover_letter' => 'I am very interested.',
    ])
        ->assertCreated()
        ->assertJsonPath('application.status', 'pending');
});

test('job seeker cannot apply twice to same job', function () {
    [, $job] = seekerFlowEmployerWithJob();
    Application::create(['user_id' => $this->seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($this->seekerToken)
        ->postJson('/api/job-seeker/apply', ['job_post_id' => (string) $job->_id])
        ->assertStatus(409);
});

test('job seeker cannot apply to inactive job', function () {
    [, $job] = seekerFlowEmployerWithJob();
    $job->update(['is_active' => false]);

    $this->withToken($this->seekerToken)
        ->postJson('/api/job-seeker/apply', ['job_post_id' => (string) $job->_id])
        ->assertNotFound();
});

test('job seeker can list their applications', function () {
    [, $job] = seekerFlowEmployerWithJob();
    Application::create(['user_id' => $this->seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($this->seekerToken)->getJson('/api/job-seeker/applications')
        ->assertOk()
        ->assertJsonStructure(['applications']);
});

test('job seeker can withdraw a pending application', function () {
    [, $job] = seekerFlowEmployerWithJob();
    $app = Application::create(['user_id' => $this->seeker->_id, 'job_post_id' => $job->_id, 'status' => 'pending', 'applied_at' => now()]);

    $this->withToken($this->seekerToken)->deleteJson("/api/job-seeker/applications/{$app->_id}/withdraw")->assertOk();

    expect(Application::find($app->_id))->toBeNull();
});

test('job seeker cannot withdraw an accepted application', function () {
    [, $job] = seekerFlowEmployerWithJob();
    $app = Application::create(['user_id' => $this->seeker->_id, 'job_post_id' => $job->_id, 'status' => 'accepted', 'applied_at' => now()]);

    $this->withToken($this->seekerToken)
        ->deleteJson("/api/job-seeker/applications/{$app->_id}/withdraw")
        ->assertForbidden();
});

// ── Direct offers ──────────────────────────────────────────────────

test('job seeker can list received direct offers', function () {
    $this->withToken($this->seekerToken)->getJson('/api/job-seeker/offers')
        ->assertOk()
        ->assertJsonStructure(['offers']);
});

test('job seeker can decline a direct offer', function () {
    [$employer, $job] = seekerFlowEmployerWithJob();
    $offer = DirectOffer::create(['employer_id' => $employer->_id, 'job_seeker_id' => $this->seeker->_id, 'job_post_id' => $job->_id, 'message' => 'Great fit.', 'status' => 'pending']);

    $this->withToken($this->seekerToken)->postJson("/api/job-seeker/offers/{$offer->_id}/decline")->assertOk();

    expect(DirectOffer::find($offer->_id)->status)->toBe('declined');
});

test('job seeker can accept a direct offer and application is created', function () {
    [$employer, $job] = seekerFlowEmployerWithJob();
    $offer = DirectOffer::create(['employer_id' => $employer->_id, 'job_seeker_id' => $this->seeker->_id, 'job_post_id' => $job->_id, 'message' => 'Join us!', 'status' => 'pending']);

    $this->withToken($this->seekerToken)->postJson("/api/job-seeker/offers/{$offer->_id}/accept")->assertOk();

    expect(DirectOffer::find($offer->_id)->status)->toBe('accepted');

    $application = Application::where('user_id', $this->seeker->_id)->where('job_post_id', $job->_id)->first();
    expect($application)->not->toBeNull();
    expect($application->status)->toBe('pending');
});

// ── Authorization ──────────────────────────────────────────────────

test('employer cannot access job seeker endpoints', function () {
    $this->withToken(tokenFor('employer'))->getJson('/api/job-seeker/profile')->assertForbidden();
});
