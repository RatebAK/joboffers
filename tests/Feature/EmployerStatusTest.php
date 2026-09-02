<?php

// =============================================================================
// EmployerStatusTest — GET /api/employer/status across application states.
// =============================================================================

use App\Models\Employer;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    [$this->user, $this->token] = userWithToken('employee');
});

/** Submit an employer application for the current user and return its id. */
function applyAsEmployer(string $token, string $filename = 'doc.pdf'): string
{
    return test()->withToken($token)
        ->postJson('/api/employer/apply', ['document' => UploadedFile::fake()->create($filename, 100, 'application/pdf')])
        ->assertCreated()
        ->json('employer._id');
}

test('status is false with no application', function () {
    $this->withToken($this->token)->getJson('/api/employer/status')
        ->assertOk()
        ->assertJsonPath('is_employer', false)
        ->assertJsonPath('latest', null);
});

test('status reflects a pending application after applying', function () {
    applyAsEmployer($this->token);

    $this->withToken($this->token)->getJson('/api/employer/status')
        ->assertOk()
        ->assertJsonPath('is_employer', false)
        ->assertJsonPath('latest.status', 'pending');
});

test('status reflects the submitted document name', function () {
    applyAsEmployer($this->token, 'my_license.pdf');

    expect($this->withToken($this->token)->getJson('/api/employer/status')->assertOk()->json('latest.document_name'))
        ->toBe('my_license.pdf');
});

test('status becomes approved after an admin approves', function () {
    $applicationId = applyAsEmployer($this->token);

    $this->withToken(tokenFor('admin'))->postJson("/api/admin/employers/{$applicationId}/approve")->assertOk();

    // Re-authenticate to pick up the updated is_employer flag.
    $freshToken = auth('api')->login(User::find($this->user->_id));

    $this->withToken($freshToken)->getJson('/api/employer/status')
        ->assertOk()
        ->assertJsonPath('is_employer', true)
        ->assertJsonPath('latest.status', 'approved');
});

test('status becomes rejected after an admin rejects', function () {
    $applicationId = applyAsEmployer($this->token);

    $this->withToken(tokenFor('admin'))
        ->postJson("/api/admin/employers/{$applicationId}/reject", ['review_notes' => 'Docs incomplete.'])
        ->assertOk();

    $this->withToken($this->token)->getJson('/api/employer/status')
        ->assertOk()
        ->assertJsonPath('is_employer', false)
        ->assertJsonPath('latest.status', 'rejected');
});

test('an unauthenticated user cannot check employer status', function () {
    $this->getJson('/api/employer/status')->assertUnauthorized();
});
