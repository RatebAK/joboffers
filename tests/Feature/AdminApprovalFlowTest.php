<?php

// ============================================================
// DO NOT DELETE — End-to-end tests for the admin approval flow.
// Covers the real path: user registers → submits employer
// application → admin sees it in pending list → approves →
// employer gains access → creates job → seeker applies →
// employer accepts the application.
// ============================================================

use App\Models\Application;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ── Helpers ───────────────────────────────────────────────────

function adminUser(): array
{
    $admin = User::factory()->create(['roles' => ['admin']]);
    $token = auth('api')->login($admin);
    return [$admin, $token];
}

function pendingEmployerUser(): array
{
    $user = User::factory()->create(['roles' => ['employee']]);
    $token = auth('api')->login($user);
    return [$user, $token];
}

// ── Registration ──────────────────────────────────────────────

test('user can register with employer role', function () {
    $email = 'employer_reg_' . uniqid() . '@test.com';

    $response = $this->postJson('/api/auth/register', [
        'name'                  => 'Test Employer',
        'email'                 => $email,
        'password'              => 'Password1!',
        'password_confirmation' => 'Password1!',
        'role'                  => 'employer',
    ]);

    $response->assertStatus(201);
    expect($response->json('user.roles'))->toContain('employer');

    User::where('email', $email)->delete();
});

// ── Apply for employer approval ───────────────────────────────

test('registered user can submit employer application with document', function () {
    Storage::fake('public');
    [$user, $token] = pendingEmployerUser();

    $file = UploadedFile::fake()->create('business_license.pdf', 100, 'application/pdf');

    $response = $this->withToken($token)->postJson('/api/employer/apply', [
        'document' => $file,
    ]);

    $response->assertStatus(201)
             ->assertJsonPath('employer.status', 'pending')
             ->assertJsonPath('employer.user_id', (string) $user->_id);

    Employer::where('user_id', $user->_id)->delete();
    $user->delete();
});

test('employer application requires a document file', function () {
    [$user, $token] = pendingEmployerUser();

    $this->withToken($token)->postJson('/api/employer/apply', [])
         ->assertStatus(422)
         ->assertJsonStructure(['document']);

    $user->delete();
});

test('employer application rejects invalid file types', function () {
    Storage::fake('public');
    [$user, $token] = pendingEmployerUser();

    $file = UploadedFile::fake()->create('resume.exe', 100, 'application/octet-stream');

    $this->withToken($token)->postJson('/api/employer/apply', [
        'document' => $file,
    ])->assertStatus(422)->assertJsonStructure(['document']);

    $user->delete();
});

// ── Admin sees pending list ───────────────────────────────────

test('admin can see pending employer applications after user applies', function () {
    Storage::fake('public');
    [$user, $userToken] = pendingEmployerUser();
    [$admin, $adminToken] = adminUser();

    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $this->withToken($userToken)->postJson('/api/employer/apply', ['document' => $file])
         ->assertStatus(201);

    $response = $this->withToken($adminToken)->getJson('/api/admin/employers')
         ->assertStatus(200);

    $userIds = collect($response->json())->pluck('user_id')->toArray();
    expect($userIds)->toContain((string) $user->_id);

    Employer::where('user_id', $user->_id)->delete();
    $user->delete();
    $admin->delete();
});

test('pending list is empty when no applications exist', function () {
    [$admin, $adminToken] = adminUser();

    // Delete any pre-existing pending records to isolate this test
    $existingIds = Employer::where('status', 'pending')->pluck('_id')->toArray();

    $response = $this->withToken($adminToken)->getJson('/api/admin/employers')
         ->assertStatus(200);

    // All returned items should be pending
    foreach ($response->json() as $item) {
        expect($item['status'])->toBe('pending');
    }

    $admin->delete();
});

test('non-admin cannot access pending employer list', function () {
    [$user, $token] = pendingEmployerUser();

    $this->withToken($token)->getJson('/api/admin/employers')
         ->assertStatus(403);

    $user->delete();
});

// ── Admin approves ────────────────────────────────────────────

test('admin can approve a pending employer application', function () {
    Storage::fake('public');
    [$user, $userToken] = pendingEmployerUser();
    [$admin, $adminToken] = adminUser();

    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $applyResponse = $this->withToken($userToken)->postJson('/api/employer/apply', ['document' => $file])
         ->assertStatus(201);

    $applicationId = $applyResponse->json('employer._id');

    $this->withToken($adminToken)->postJson("/api/admin/{$applicationId}/approve")
         ->assertStatus(200)
         ->assertJsonPath('employer.status', 'approved');

    $updatedUser = User::find($user->_id);
    expect($updatedUser->roles)->toContain('employer');
    expect((bool) $updatedUser->is_employer)->toBeTrue();

    Employer::where('user_id', $user->_id)->delete();
    $user->delete();
    $admin->delete();
});

test('admin can reject a pending employer application with notes', function () {
    Storage::fake('public');
    [$user, $userToken] = pendingEmployerUser();
    [$admin, $adminToken] = adminUser();

    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $applyResponse = $this->withToken($userToken)->postJson('/api/employer/apply', ['document' => $file])
         ->assertStatus(201);

    $applicationId = $applyResponse->json('employer._id');

    $this->withToken($adminToken)->postJson("/api/admin/{$applicationId}/reject", [
        'review_notes' => 'Insufficient documentation.',
    ])->assertStatus(200)
      ->assertJsonPath('employer.status', 'rejected')
      ->assertJsonPath('employer.review_notes', 'Insufficient documentation.');

    Employer::where('user_id', $user->_id)->delete();
    $user->delete();
    $admin->delete();
});

test('approved employer can access employer routes', function () {
    Storage::fake('public');
    [$user, $userToken] = pendingEmployerUser();
    [$admin, $adminToken] = adminUser();

    // Apply
    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $applyResponse = $this->withToken($userToken)->postJson('/api/employer/apply', ['document' => $file])
         ->assertStatus(201);
    $applicationId = $applyResponse->json('employer._id');

    // Approve
    $this->withToken($adminToken)->postJson("/api/admin/{$applicationId}/approve")
         ->assertStatus(200);

    // Re-login to get fresh token with updated roles
    $updatedUser = User::find($user->_id);
    $freshToken = auth('api')->login($updatedUser);

    // Can now create a job post
    $this->withToken($freshToken)->postJson('/api/employer/jobs', [
        'title'        => 'Approved Employer Job',
        'description'  => 'Test.',
        'requirements' => 'Test.',
        'company_name' => 'Test Co',
        'job_type'     => 'full_time',
    ])->assertStatus(201);

    JobPost::where('employer_id', (string) $user->_id)->delete();
    Employer::where('user_id', $user->_id)->delete();
    $user->delete();
    $admin->delete();
});

test('unapproved employer cannot access employer routes', function () {
    Storage::fake('public');
    [$user, $userToken] = pendingEmployerUser();

    // Apply but don't approve
    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $this->withToken($userToken)->postJson('/api/employer/apply', ['document' => $file])
         ->assertStatus(201);

    // Cannot create job posts yet
    $this->withToken($userToken)->postJson('/api/employer/jobs', [
        'title'        => 'Sneaky Job',
        'description'  => 'Test.',
        'requirements' => 'Test.',
        'company_name' => 'Test Co',
        'job_type'     => 'full_time',
    ])->assertStatus(403);

    Employer::where('user_id', $user->_id)->delete();
    $user->delete();
});

// ── Full end-to-end: register → approve → post job → apply → accept ──

test('full flow: employer registers, gets approved, posts job, seeker applies, employer accepts', function () {
    Storage::fake('public');

    // 1. Admin exists
    [$admin, $adminToken] = adminUser();

    // 2. Employer registers and applies
    [$employerUser, $employerToken] = pendingEmployerUser();
    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $applyResponse = $this->withToken($employerToken)->postJson('/api/employer/apply', ['document' => $file])
         ->assertStatus(201);
    $applicationId = $applyResponse->json('employer._id');

    // 3. Admin sees the application in pending list
    $pendingList = $this->withToken($adminToken)->getJson('/api/admin/employers')->assertStatus(200);
    $userIds = collect($pendingList->json())->pluck('user_id')->toArray();
    expect($userIds)->toContain((string) $employerUser->_id);

    // 4. Admin approves
    $this->withToken($adminToken)->postJson("/api/admin/{$applicationId}/approve")
         ->assertStatus(200);

    // 5. Employer gets fresh token and creates a job post
    $approvedEmployer = User::find($employerUser->_id);
    $freshEmployerToken = auth('api')->login($approvedEmployer);

    $jobResponse = $this->withToken($freshEmployerToken)->postJson('/api/employer/jobs', [
        'title'        => 'E2E Test Job',
        'description'  => 'Full end-to-end test job.',
        'requirements' => 'Testing skills.',
        'company_name' => 'E2E Corp',
        'job_type'     => 'full_time',
        'location'     => 'Remote',
    ])->assertStatus(201);
    $jobId = $jobResponse->json('id');

    // 6. Job appears in public listing
    $publicList = $this->getJson('/api/jobs')->assertStatus(200);
    $publicIds = collect($publicList->json('data'))->pluck('id')->toArray();
    expect($publicIds)->toContain($jobId);

    // 7. Job seeker registers and applies
    $seeker = User::factory()->employee()->create();
    $seekerToken = auth('api')->login($seeker);

    $appResponse = $this->withToken($seekerToken)->postJson('/api/job-seeker/apply', [
        'job_post_id'  => $jobId,
        'cover_letter' => 'I am a great fit for this role.',
    ])->assertStatus(201);
    $applicationRecordId = $appResponse->json('application.id');

    // 8. Employer sees the application
    $apps = $this->withToken($freshEmployerToken)->getJson("/api/employer/jobs/{$jobId}/applications")
         ->assertStatus(200);
    $appIds = collect($apps->json('applications.data'))->pluck('id')->toArray();
    expect($appIds)->toContain($applicationRecordId);

    // 9. Employer accepts the application
    $this->withToken($freshEmployerToken)->putJson("/api/employer/applications/{$applicationRecordId}/status", [
        'status'   => 'accepted',
        'feedback' => 'Welcome aboard!',
    ])->assertStatus(200)->assertJsonPath('application.status', 'accepted');

    // Cleanup
    Application::where('user_id', $seeker->_id)->delete();
    JobPost::where('employer_id', (string) $employerUser->_id)->delete();
    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    Employer::where('user_id', $employerUser->_id)->delete();
    $seeker->delete();
    $employerUser->delete();
    $admin->delete();
});
