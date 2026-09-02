<?php

// End-to-end admin approval flow: a user applies to become an employer, the
// admin sees the pending application and approves/rejects it, and an approved
// employer gains access to employer routes.

use App\Models\Employer;
use App\Models\JobPost;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Submit an employer application (document upload) for the given user token. */
function submitEmployerApplication(string $token): string
{
    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

    return test()->withToken($token)
        ->postJson('/api/employer/apply', ['document' => $file])
        ->assertCreated()
        ->json('employer._id');
}

beforeEach(function () {
    Storage::fake('public');
    [$this->admin, $this->adminToken] = userWithToken('admin');
});

// ── Registration ─────────────────────────────────────────────────────

test('a user can register with the employer role', function () {
    $response = $this->postJson('/api/auth/register', [
        'name'                  => 'Test Employer',
        'email'                 => 'employer_reg_'.uniqid().'@test.com',
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'role'                  => 'employer',
    ])->assertCreated();

    expect($response->json('user.roles'))->toContain('employer');
});

// ── Apply for employer approval ──────────────────────────────────────

test('a user can submit an employer application with a document', function () {
    [$user, $token] = userWithToken('employee');
    $file = UploadedFile::fake()->create('business_license.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/employer/apply', ['document' => $file])
        ->assertCreated()
        ->assertJsonPath('employer.status', 'pending')
        ->assertJsonPath('employer.user_id', (string) $user->_id);
});

// apply uses $request->validate() → top-level validation error shape
test('the employer application requires a document file', function () {
    $this->withToken(tokenFor('employee'))
        ->postJson('/api/employer/apply', [])
        ->assertStatus(422)
        ->assertJsonStructure(['document']);
});

test('the employer application rejects invalid file types', function () {
    $file = UploadedFile::fake()->create('resume.exe', 100, 'application/octet-stream');

    $this->withToken(tokenFor('employee'))
        ->postJson('/api/employer/apply', ['document' => $file])
        ->assertStatus(422)
        ->assertJsonStructure(['document']);
});

// ── Admin sees pending list ──────────────────────────────────────────

test('admin sees a pending application after the user applies', function () {
    [$user, $userToken] = userWithToken('employee');
    submitEmployerApplication($userToken);

    $response = $this->withToken($this->adminToken)->getJson('/api/admin/employers')
        ->assertOk();

    $userIds = collect($response->json())->pluck('user_id')->toArray();
    expect($userIds)->toContain((string) $user->_id);
});

test('every entry in the pending list has pending status', function () {
    [, $userToken] = userWithToken('employee');
    submitEmployerApplication($userToken);

    $response = $this->withToken($this->adminToken)->getJson('/api/admin/employers')
        ->assertOk();

    foreach ($response->json() as $item) {
        expect($item['status'])->toBe('pending');
    }
});

test('a non-admin cannot access the pending employer list', function () {
    $this->withToken(tokenFor('employee'))
        ->getJson('/api/admin/employers')
        ->assertForbidden();
});

// ── Admin approves / rejects ─────────────────────────────────────────
// NOTE: approve/reject routes are POST /api/admin/employers/{id}/{action}
// (the old tests used POST /api/admin/{id}/approve, which no route matches).

test('admin can approve a pending employer application', function () {
    [$user, $userToken] = userWithToken('employee');
    $applicationId = submitEmployerApplication($userToken);

    $this->withToken($this->adminToken)
        ->postJson("/api/admin/employers/{$applicationId}/approve")
        ->assertOk()
        ->assertJsonPath('employer.status', 'approved');

    $updated = $user->fresh();
    expect($updated->roles)->toContain('employer')
        ->and((bool) $updated->is_employer)->toBeTrue();
});

test('admin can reject a pending employer application with notes', function () {
    [, $userToken] = userWithToken('employee');
    $applicationId = submitEmployerApplication($userToken);

    $this->withToken($this->adminToken)
        ->postJson("/api/admin/employers/{$applicationId}/reject", [
            'review_notes' => 'Insufficient documentation.',
        ])
        ->assertOk()
        ->assertJsonPath('employer.status', 'rejected')
        ->assertJsonPath('employer.review_notes', 'Insufficient documentation.');
});

// ── Access control after approval ────────────────────────────────────

test('an approved employer can access employer routes', function () {
    [$user, $userToken] = userWithToken('employee');
    $applicationId = submitEmployerApplication($userToken);

    $this->withToken($this->adminToken)
        ->postJson("/api/admin/employers/{$applicationId}/approve")
        ->assertOk();

    // A company profile is required before posting a job (JobPostController::store).
    createCompanyFor($user->fresh());
    $freshToken = auth('api')->login($user->fresh());

    $this->withToken($freshToken)->postJson('/api/employer/jobs', [
        'title'                => 'Approved Employer Job',
        'description'          => 'Test.',
        'communication_method' => 'by_forsa',
        'vacancies'            => 1,
        'job_type'             => 'full_time',
        'city'                 => 'Beirut',
    ])->assertCreated();
});

test('an unapproved employer cannot access employer routes', function () {
    [, $userToken] = userWithToken('employee');
    submitEmployerApplication($userToken);

    $this->withToken($userToken)->postJson('/api/employer/jobs', [
        'title'                => 'Sneaky Job',
        'description'          => 'Test.',
        'communication_method' => 'by_forsa',
        'vacancies'            => 1,
        'job_type'             => 'full_time',
        'city'                 => 'Beirut',
    ])->assertForbidden();
});

// ── Full end-to-end: apply → approve → post job → seeker applies → accept ──

test('full flow: apply, approve, post job, seeker applies, employer accepts', function () {
    // 1. Employer applies
    [$employerUser, $employerToken] = userWithToken('employee');
    $applicationId = submitEmployerApplication($employerToken);

    // 2. Admin sees it pending
    $pendingList = $this->withToken($this->adminToken)->getJson('/api/admin/employers')->assertOk();
    expect(collect($pendingList->json())->pluck('user_id'))->toContain((string) $employerUser->_id);

    // 3. Admin approves
    $this->withToken($this->adminToken)
        ->postJson("/api/admin/employers/{$applicationId}/approve")
        ->assertOk();

    // 4. Approved employer sets up a company and posts a job
    createCompanyFor($employerUser->fresh(), ['name' => 'E2E Corp']);
    $freshEmployerToken = auth('api')->login($employerUser->fresh());

    $this->withToken($freshEmployerToken)->postJson('/api/employer/jobs', [
        'title'                => 'E2E Test Job',
        'description'          => 'Full end-to-end test job.',
        'requirements'         => 'Testing skills.',
        'communication_method' => 'by_forsa',
        'vacancies'            => 1,
        'job_type'             => 'full_time',
        'city'                 => 'Remote',
    ])->assertCreated();
    $jobId = (string) JobPost::where('employer_id', (string) $employerUser->_id)->firstOrFail()->_id;

    // 5. Job appears in the public listing
    $publicList = $this->getJson('/api/jobs')->assertOk();
    $publicTitles = collect($publicList->json('data'))->pluck('title')->toArray();
    expect($publicTitles)->toContain('E2E Test Job');

    // 6. Seeker (with a CV on file) applies
    [$seeker, $seekerToken] = userWithToken('employee');
    \App\Models\JobSeekerProfile::create([
        'user_id'      => (string) $seeker->_id,
        'cv_file_path' => 'https://example.com/cv.pdf',
    ]);

    $this->withToken($seekerToken)->postJson('/api/job-seeker/apply', [
        'job_post_id'  => $jobId,
        'cover_letter' => 'I am a great fit for this role.',
    ])->assertCreated();
    $applicationRecordId = (string) \App\Models\Application::where('user_id', (string) $seeker->_id)
        ->where('job_post_id', $jobId)
        ->firstOrFail()->_id;

    // 7. Employer sees the application
    $apps = $this->withToken($freshEmployerToken)->getJson("/api/employer/jobs/{$jobId}/applications")->assertOk();
    $appCount = collect($apps->json('applications.data'))->count();
    expect($appCount)->toBeGreaterThanOrEqual(1);

    // 8. Employer accepts the application
    $this->withToken($freshEmployerToken)
        ->putJson("/api/employer/applications/{$applicationRecordId}/status", [
            'status'   => 'accepted',
            'feedback' => 'Welcome aboard!',
        ])
        ->assertOk()
        ->assertJsonPath('application.status', 'accepted');
});

// ── Full HTTP-only flow: register → apply → approve → login → post ───

test('full http flow: register employer, apply, admin approves, employer posts', function () {
    $uid = uniqid();

    // 1. Register as employer (auto-creates a pending Employer with _id == user_id)
    $employerEmail   = "employer_{$uid}@test.com";
    $registerEmployer = $this->postJson('/api/auth/register', [
        'name'                  => 'Test Employer',
        'email'                 => $employerEmail,
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'role'                  => 'employer',
    ])->assertCreated();

    $employerToken  = $registerEmployer->json('access_token');
    expect($registerEmployer->json('user.roles'))->toContain('employer');

    $employerUserId = (string) \App\Models\User::where('email', $employerEmail)->firstOrFail()->_id;

    // 2. Registering as employer does NOT yet grant is_employer, so employer
    //    routes remain blocked until an admin approves.
    $this->withToken($employerToken)->postJson('/api/employer/jobs', [
        'title'                => 'Too Early',
        'description'          => 'x',
        'communication_method' => 'by_forsa',
        'vacancies'            => 1,
        'job_type'             => 'full_time',
        'city'                 => 'Beirut',
    ])->assertForbidden();

    // 3. Admin approves the auto-created pending application (id == user id).
    $this->withToken($this->adminToken)
        ->postJson("/api/admin/employers/{$employerUserId}/approve")
        ->assertOk()
        ->assertJsonPath('employer.status', 'approved');

    // 4. Employer logs in fresh and now has the employer role approved.
    $login = $this->postJson('/api/auth/login', [
        'email'    => $employerEmail,
        'password' => 'Password1!',
    ])->assertOk();
    $freshToken = $login->json('access_token');
    expect($login->json('user.roles'))->toContain('employer');

    // 5. With a company profile in place, the employer can post a job.
    $employerUser = \App\Models\User::find($employerUserId);
    createCompanyFor($employerUser, ['name' => 'My Company']);

    $this->withToken($freshToken)->postJson('/api/employer/jobs', [
        'title'                => 'Approved Employer Job',
        'description'          => 'Now I can post.',
        'requirements'         => 'Skills needed.',
        'communication_method' => 'by_forsa',
        'vacancies'            => 1,
        'job_type'             => 'full_time',
        'city'                 => 'Beirut',
    ])->assertCreated();
});
