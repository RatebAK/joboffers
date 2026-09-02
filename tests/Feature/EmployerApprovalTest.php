<?php

// =============================================================================
// EmployerApprovalTest
//
// The employer approval flow: unapproved employers are gated, anyone can apply
// and check status, admins approve/reject (adding the employer role and writing
// an audit log), and an approved employer can go on to post jobs.
// =============================================================================

use App\Models\AuditLog;
use App\Models\Employer;
use App\Models\User;

/** A pending employer application for a fresh (unapproved) applicant. */
function pendingApplication(array $userAttributes = []): array
{
    $user = createUser('employee', $userAttributes);

    $application = Employer::create([
        'user_id'       => (string) $user->_id,
        'document_path' => 'employer_docs/test.pdf',
        'document_name' => 'test.pdf',
        'status'        => Employer::STATUS_PENDING,
    ]);

    return [$user, $application];
}

// ── Access gate ──────────────────────────────────────────────────────────

test('an unapproved employer is blocked from protected employer routes', function () {
    $token = tokenFor('employer', ['roles' => ['employer'], 'is_employer' => false]);

    $this->withToken($token)->getJson('/api/employer/jobs')
        ->assertForbidden()
        ->assertJsonPath('message', 'Your employer account is pending admin approval.');
});

test('an approved employer can access protected employer routes', function () {
    $this->withToken(tokenFor('employer'))->getJson('/api/employer/jobs')->assertOk();
});

test('an admin has universal access to employer and seeker routes', function () {
    $token = tokenFor('admin');

    $this->withToken($token)->getJson('/api/employer/jobs')->assertOk();
    $this->withToken($token)->getJson('/api/job-seeker/profile')->assertOk();
});

test('a dual-role user can access both seeker and employer routes', function () {
    $token = tokenFor('employer', ['roles' => ['employee', 'employer'], 'is_employer' => true]);

    $this->withToken($token)->getJson('/api/job-seeker/profile')->assertOk();
    $this->withToken($token)->getJson('/api/employer/jobs')->assertOk();
});

test('a non-admin cannot access admin employer routes', function () {
    $this->withToken(tokenFor('employer'))->getJson('/api/admin/employers')->assertForbidden();
});

// ── Applying / status (open to any authenticated user) ───────────────────

test('any authenticated user can submit an employer application', function () {
    // Empty body → 422 (not 401/403), proving the route is open to seekers.
    $this->withToken(tokenFor('employee'))->postJson('/api/employer/apply', [])->assertStatus(422);
});

test('any authenticated user can check their employer status', function () {
    $this->withToken(tokenFor('employee'))->getJson('/api/employer/status')
        ->assertOk()
        ->assertJsonStructure(['is_employer', 'latest']);
});

// ── Admin: list pending ──────────────────────────────────────────────────

test('an admin sees pending applications with the applicant embedded', function () {
    [$applicant] = pendingApplication(['email' => 'applicant@example.com']);

    $applications = $this->withToken(tokenFor('admin'))
        ->getJson('/api/admin/employers')
        ->assertOk()
        ->json();

    expect($applications)->toHaveCount(1)
        ->and($applications[0]['status'])->toBe(Employer::STATUS_PENDING)
        ->and($applications[0]['user']['email'])->toBe('applicant@example.com');
});

// ── Admin: approve ───────────────────────────────────────────────────────

test('approving an application adds the employer role and flag to the user', function () {
    [$applicant, $application] = pendingApplication();

    $this->withToken(tokenFor('admin'))
        ->postJson("/api/admin/employers/{$application->_id}/approve")
        ->assertOk()
        ->assertJsonPath('message', 'Approved employer request.');

    $updated = User::find($applicant->_id);
    expect($updated->is_employer)->toBeTrue()
        ->and($updated->roles)->toContain('employee')
        ->and($updated->roles)->toContain('employer');
    expect(Employer::find($application->_id)->status)->toBe(Employer::STATUS_APPROVED);
});

test('approving an application writes an employer_approved audit log', function () {
    [, $application] = pendingApplication();

    $this->withToken(tokenFor('admin'))
        ->postJson("/api/admin/employers/{$application->_id}/approve")
        ->assertOk();

    $log = AuditLog::where('action', 'employer_approved')
        ->where('target_id', (string) $application->_id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->target_type)->toBe('Employer');
});

// ── Admin: reject ────────────────────────────────────────────────────────

test('rejecting an application records the status and review notes', function () {
    [, $application] = pendingApplication();

    $this->withToken(tokenFor('admin'))
        ->postJson("/api/admin/employers/{$application->_id}/reject", ['review_notes' => 'Documents not valid.'])
        ->assertOk()
        ->assertJsonPath('message', 'Rejected employer request.');

    $rejected = Employer::find($application->_id);
    expect($rejected->status)->toBe(Employer::STATUS_REJECTED)
        ->and($rejected->review_notes)->toBe('Documents not valid.');
});

test('rejecting an application writes an employer_rejected audit log', function () {
    [, $application] = pendingApplication();

    $this->withToken(tokenFor('admin'))
        ->postJson("/api/admin/employers/{$application->_id}/reject", ['review_notes' => 'Insufficient docs'])
        ->assertOk();

    expect(AuditLog::where('action', 'employer_rejected')->where('target_id', (string) $application->_id)->exists())
        ->toBeTrue();
});

// ── End-to-end ───────────────────────────────────────────────────────────

test('an approved employer can create a company profile and post a job', function () {
    [$applicant, $application] = pendingApplication();

    $this->withToken(tokenFor('admin'))
        ->postJson("/api/admin/employers/{$application->_id}/approve")
        ->assertOk();

    // Re-authenticate so the token reflects the newly granted employer role.
    $token = auth('api')->login(User::find($applicant->_id));

    $this->withToken($token)->postJson('/api/employer/company', ['name' => 'Test Company Inc'])->assertCreated();

    $this->withToken($token)->postJson('/api/employer/jobs', [
        'title'                => 'Senior PHP Developer',
        'description'          => 'Join our team.',
        'vacancies'            => 1,
        'city'                 => 'Damascus',
        'job_type'             => 'full_time',
        'communication_method' => 'by_forsa',
        'tags'                 => ['PHP', 'Laravel'],
    ])
        ->assertCreated()
        ->assertJsonPath('title', 'Senior PHP Developer')
        ->assertJsonPath('is_active', true);
});

test('registering with the employer role creates a pending application and leaves is_employer false', function () {
    $this->postJson('/api/auth/register', [
        'name'                  => 'Test Employer',
        'email'                 => 'newemployer@example.com',
        'password'              => 'Password@123',
        'password_confirmation' => 'Password@123',
        'role'                  => 'employer',
    ])->assertCreated();

    $user = User::where('email', 'newemployer@example.com')->first();
    expect($user->roles)->toContain('employer')
        ->and($user->is_employer)->toBeFalsy();
    expect(Employer::where('user_id', (string) $user->_id)->first()->status)->toBe(Employer::STATUS_PENDING);
});
