<?php

// ============================================================
// DO NOT DELETE — Tests for the employer approval flow.
// Verifies that unapproved employers are blocked from protected
// routes, any authenticated user can apply to become an employer,
// a job seeker can gain dual employee+employer roles after approval,
// and admin approve/reject work correctly.
// ============================================================

use App\Models\Employer;
use App\Models\JobPost;
use App\Models\User;

test('unapproved employer is blocked from protected employer routes', function () {
    $employer = User::factory()->state(['roles' => ['employer'], 'is_employer' => false])->create();
    $token = auth('api')->login($employer);

    $this->withToken($token)->getJson('/api/employer/jobs')
         ->assertStatus(403)
         ->assertJsonPath('message', 'Your employer account is pending admin approval.');

    $employer->delete();
});

test('any authenticated user can submit an employer application', function () {
    // A plain employee (job seeker) can apply to become an employer
    $seeker = User::factory()->employee()->create();
    $token = auth('api')->login($seeker);

    // No file = 422 validation error, NOT 401/403 — proves the route is open
    $this->withToken($token)->postJson('/api/employer/apply', [])
         ->assertStatus(422);

    $seeker->delete();
});

test('any authenticated user can check employer application status', function () {
    $seeker = User::factory()->employee()->create();
    $token = auth('api')->login($seeker);

    $this->withToken($token)->getJson('/api/employer/status')
         ->assertStatus(200)
         ->assertJsonStructure(['is_employer', 'latest']);

    $seeker->delete();
});

test('unapproved employer can check their application status', function () {
    $employer = User::factory()->state(['roles' => ['employer'], 'is_employer' => false])->create();
    $token = auth('api')->login($employer);

    $response = $this->withToken($token)->getJson('/api/employer/status')->assertStatus(200);
    expect($response->json('is_employer'))->toBeFalsy();

    $employer->delete();
});

test('approved employer can access protected employer routes', function () {
    $employer = User::factory()->employer()->create(); // is_employer=true via factory
    $token = auth('api')->login($employer);

    $this->withToken($token)->getJson('/api/employer/jobs')->assertStatus(200);

    JobPost::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('admin can approve an employer application and employer role is added', function () {
    $admin = User::factory()->admin()->create();
    $adminToken = auth('api')->login($admin);

    // Start as a plain employee (job seeker)
    $seeker = User::factory()->employee()->create();

    $application = Employer::create([
        'user_id'       => $seeker->_id,
        'document_path' => 'employer_docs/test.pdf',
        'document_name' => 'test.pdf',
        'status'        => Employer::STATUS_PENDING,
    ]);

    $this->withToken($adminToken)
         ->postJson("/api/admin/{$seeker->_id}/approve")
         ->assertStatus(200)
         ->assertJsonPath('message', 'Approved employer request.');

    $updated = User::find($seeker->_id);
    // User now has is_employer=true
    expect($updated->is_employer)->toBeTrue();
    // User now has BOTH roles — still a job seeker AND now an employer
    expect($updated->roles)->toContain('employee');
    expect($updated->roles)->toContain('employer');

    $application->delete();
    $seeker->delete();
    $admin->delete();
});

test('admin can reject an employer application', function () {
    $admin = User::factory()->admin()->create();
    $adminToken = auth('api')->login($admin);

    $employer = User::factory()->state(['roles' => ['employer'], 'is_employer' => false])->create();

    $application = Employer::create([
        'user_id'       => $employer->_id,
        'document_path' => 'employer_docs/test.pdf',
        'document_name' => 'test.pdf',
        'status'        => Employer::STATUS_PENDING,
    ]);

    $this->withToken($adminToken)
         ->postJson("/api/admin/{$employer->_id}/reject", [
             'review_notes' => 'Documents not valid.',
         ])
         ->assertStatus(200)
         ->assertJsonPath('message', 'Rejected employer request.');

    expect(Employer::find($employer->_id)->status)->toBe(Employer::STATUS_REJECTED);

    $application->delete();
    $employer->delete();
    $admin->delete();
});

test('dual-role user can access both job seeker and employer routes after approval', function () {
    // Simulate a user who was approved: has both roles and is_employer=true
    $user = User::factory()->state([
        'roles'       => ['employee', 'employer'],
        'is_employer' => true,
    ])->create();
    $token = auth('api')->login($user);

    // Can access job seeker routes
    $this->withToken($token)->getJson('/api/job-seeker/profile')->assertStatus(200);
    // Can access employer routes
    $this->withToken($token)->getJson('/api/employer/jobs')->assertStatus(200);

    JobPost::where('employer_id', (string) $user->_id)->delete();
    $user->delete();
});

test('admin has universal access to all routes', function () {
    $admin = User::factory()->admin()->create();
    $token = auth('api')->login($admin);

    $this->withToken($token)->getJson('/api/employer/jobs')->assertStatus(200);
    $this->withToken($token)->getJson('/api/job-seeker/profile')->assertStatus(200);

    $admin->delete();
});

test('non-admin cannot access admin routes', function () {
    $employer = User::factory()->employer()->create();
    $token = auth('api')->login($employer);

    $this->withToken($token)->getJson('/api/admin/employers')->assertStatus(403);

    $employer->delete();
});
