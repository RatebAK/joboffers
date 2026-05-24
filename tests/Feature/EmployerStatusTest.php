<?php

// ============================================================
// DO NOT DELETE — Tests for employer application status endpoint.
// Covers: status when no application exists, pending status,
// approved status, rejected status, and auth guard.
// ============================================================

use App\Models\Employer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ── Helpers ───────────────────────────────────────────────────

function statusUser(): array
{
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    return [$user, $token];
}

function statusAdmin(): array
{
    $admin = User::factory()->admin()->create();
    $token = auth('api')->login($admin);
    return [$admin, $token];
}

// ── GET /api/employer/status ──────────────────────────────────

test('status returns is_employer false and null latest when no application exists', function () {
    [$user, $token] = statusUser();

    $response = $this->withToken($token)->getJson('/api/employer/status');

    $response->assertStatus(200)
             ->assertJsonPath('is_employer', false)
             ->assertJsonPath('latest', null);

    $user->delete();
});

test('status returns pending latest after submitting application', function () {
    Storage::fake('public');
    [$user, $token] = statusUser();

    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $this->withToken($token)->postJson('/api/employer/apply', ['document' => $file])
         ->assertStatus(201);

    $response = $this->withToken($token)->getJson('/api/employer/status');

    $response->assertStatus(200)
             ->assertJsonPath('is_employer', false)
             ->assertJsonPath('latest.status', 'pending');

    Employer::where('user_id', $user->_id)->delete();
    $user->delete();
});

test('status returns approved and is_employer true after admin approves', function () {
    Storage::fake('public');
    [$user, $token]   = statusUser();
    [$admin, $adminToken] = statusAdmin();

    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $applyResponse = $this->withToken($token)->postJson('/api/employer/apply', ['document' => $file])
         ->assertStatus(201);

    $applicationId = $applyResponse->json('employer._id');

    $this->withToken($adminToken)->postJson("/api/admin/{$applicationId}/approve")
         ->assertStatus(200);

    // Re-login to get fresh token with updated user state
    $updatedUser = User::find($user->_id);
    $freshToken  = auth('api')->login($updatedUser);

    $response = $this->withToken($freshToken)->getJson('/api/employer/status');

    $response->assertStatus(200)
             ->assertJsonPath('is_employer', true)
             ->assertJsonPath('latest.status', 'approved');

    Employer::where('user_id', $user->_id)->delete();
    $user->delete();
    $admin->delete();
});

test('status returns rejected after admin rejects', function () {
    Storage::fake('public');
    [$user, $token]       = statusUser();
    [$admin, $adminToken] = statusAdmin();

    $file = UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');
    $applyResponse = $this->withToken($token)->postJson('/api/employer/apply', ['document' => $file])
         ->assertStatus(201);

    $applicationId = $applyResponse->json('employer._id');

    $this->withToken($adminToken)->postJson("/api/admin/{$applicationId}/reject", [
        'review_notes' => 'Docs incomplete.',
    ])->assertStatus(200);

    $response = $this->withToken($token)->getJson('/api/employer/status');

    $response->assertStatus(200)
             ->assertJsonPath('is_employer', false)
             ->assertJsonPath('latest.status', 'rejected');

    Employer::where('user_id', $user->_id)->delete();
    $user->delete();
    $admin->delete();
});

test('status returns latest application document_name', function () {
    Storage::fake('public');
    [$user, $token] = statusUser();

    $file = UploadedFile::fake()->create('my_license.pdf', 100, 'application/pdf');
    $this->withToken($token)->postJson('/api/employer/apply', ['document' => $file])
         ->assertStatus(201);

    $response = $this->withToken($token)->getJson('/api/employer/status');

    $response->assertStatus(200);
    expect($response->json('latest.document_name'))->toBe('my_license.pdf');

    Employer::where('user_id', $user->_id)->delete();
    $user->delete();
});

test('unauthenticated user cannot check employer status', function () {
    $this->getJson('/api/employer/status')->assertStatus(401);
});
