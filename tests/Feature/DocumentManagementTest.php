<?php

// =============================================================================
// DocumentManagementTest
//
// Saved CV / resume / cover-letter management: the profile documents block,
// resume delete, default cover letter save/update/delete, apply-time fallbacks,
// and offer-accept pulling profile documents. External services are mocked.
// =============================================================================

use App\Models\Application;
use App\Models\DirectOffer;
use App\Models\JobSeekerProfile;

beforeEach(function () {
    [$this->seeker, $this->token] = userWithToken('employee');
});

/** Update (or create) the current seeker's profile with the given attributes. */
function setSeekerProfile(array $attributes): JobSeekerProfile
{
    $profile = JobSeekerProfile::firstOrCreate(['user_id' => (string) test()->seeker->_id]);
    $profile->update($attributes);

    return $profile;
}

// ── Profile documents block ──────────────────────────────────────────────

test('the profile response includes a documents block', function () {
    $this->withToken($this->token)->getJson('/api/job-seeker/profile')
        ->assertOk()
        ->assertJsonStructure(['documents' => ['cv_url', 'cv_analyzed_at', 'resume_url', 'default_cover_letter']]);
});

test('the documents block is empty before anything is uploaded', function () {
    $this->withToken($this->token)->getJson('/api/job-seeker/profile')
        ->assertOk()
        ->assertJsonPath('documents.cv_url', null)
        ->assertJsonPath('documents.resume_url', null)
        ->assertJsonPath('documents.default_cover_letter', null);
});

// ── Resume delete ────────────────────────────────────────────────────────

test('a seeker can delete their saved resume', function () {
    fakeDocumentUpload();
    fakeCvAnalysis(['full_name' => 'Test User', 'ats_score' => 75]);

    $this->withToken($this->token)
        ->postJson('/api/job-seeker/resume/upload', ['resume' => \Illuminate\Http\UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
        ->assertOk();

    $this->withToken($this->token)->deleteJson('/api/job-seeker/resume')
        ->assertOk()
        ->assertJsonPath('message', 'Resume deleted successfully');

    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile->resume)->toBeNull()
        ->and($profile->cv_file_path)->toBeNull();
});

test('deleting a resume that does not exist returns 404', function () {
    $this->withToken($this->token)->deleteJson('/api/job-seeker/resume')
        ->assertNotFound()
        ->assertJsonPath('message', 'No resume found on your profile');
});

// ── Default cover letter ─────────────────────────────────────────────────

test('a seeker can save and then update a default cover letter', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/cover-letter', ['cover_letter' => 'First version.'])
        ->assertOk()
        ->assertJsonPath('message', 'Default cover letter saved');

    $this->withToken($this->token)->putJson('/api/job-seeker/cover-letter', ['cover_letter' => 'Updated version.'])
        ->assertOk()
        ->assertJsonPath('default_cover_letter', 'Updated version.');

    expect(JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first()->default_cover_letter)
        ->toBe('Updated version.');
});

test('saving a cover letter requires content and caps at 2000 characters', function (mixed $value) {
    $payload = $value === null ? [] : ['cover_letter' => $value];

    $this->withToken($this->token)->putJson('/api/job-seeker/cover-letter', $payload)
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['cover_letter']]);
})->with(['missing' => null, 'too long' => str_repeat('a', 2001)]);

test('a saved cover letter appears in the profile documents block', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/cover-letter', ['cover_letter' => 'My default letter.'])->assertOk();

    $this->withToken($this->token)->getJson('/api/job-seeker/profile')
        ->assertOk()
        ->assertJsonPath('documents.default_cover_letter', 'My default letter.');
});

test('a seeker can delete their default cover letter', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/cover-letter', ['cover_letter' => 'To be deleted.'])->assertOk();

    $this->withToken($this->token)->deleteJson('/api/job-seeker/cover-letter')
        ->assertOk()
        ->assertJsonPath('message', 'Default cover letter deleted');

    expect(JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first()->default_cover_letter)->toBeNull();
});

test('deleting a cover letter that does not exist returns 404', function () {
    $this->withToken($this->token)->deleteJson('/api/job-seeker/cover-letter')
        ->assertNotFound()
        ->assertJsonPath('message', 'No default cover letter found on your profile');
});

// ── Apply-time fallbacks ─────────────────────────────────────────────────

test('applying uses the profile default cover letter when none is provided', function () {
    $job = createJob(createUser('employer'));
    setSeekerProfile(['default_cover_letter' => 'Auto cover letter.']);

    $this->withToken($this->token)->postJson('/api/job-seeker/apply', ['job_post_id' => (string) $job->_id])->assertCreated();

    expect(Application::where('user_id', (string) $this->seeker->_id)->first()->cover_letter)->toBe('Auto cover letter.');
});

test('an explicit cover letter on apply overrides the profile default', function () {
    $job = createJob(createUser('employer'));
    setSeekerProfile(['default_cover_letter' => 'Profile default.']);

    $this->withToken($this->token)->postJson('/api/job-seeker/apply', [
        'job_post_id'  => (string) $job->_id,
        'cover_letter' => 'Custom per-application letter.',
    ])->assertCreated();

    expect(Application::where('user_id', (string) $this->seeker->_id)->first()->cover_letter)->toBe('Custom per-application letter.');
});

test('applying uses the profile cv when no per-application resume is uploaded', function () {
    $job = createJob(createUser('employer'));
    setSeekerProfile(['cv_file_path' => 'https://cloudinary.example/cv.pdf']);

    $this->withToken($this->token)->postJson('/api/job-seeker/apply', ['job_post_id' => (string) $job->_id])->assertCreated();

    expect(Application::where('user_id', (string) $this->seeker->_id)->first()->resume)->toBe('https://cloudinary.example/cv.pdf');
});

// ── Offer accept pulls profile documents ─────────────────────────────────

test('accepting an offer auto-creates an application with the profile cv and cover letter', function () {
    $employer = createUser('employer');
    $job      = createJob($employer);
    setSeekerProfile(['cv_file_path' => 'https://cloudinary.example/cv.pdf', 'default_cover_letter' => 'Offer acceptance letter.']);

    $offer = DirectOffer::create([
        'employer_id'   => (string) $employer->_id,
        'job_seeker_id' => (string) $this->seeker->_id,
        'job_post_id'   => (string) $job->_id,
        'message'       => 'We want you.',
        'status'        => 'pending',
    ]);

    $this->withToken($this->token)->postJson("/api/job-seeker/offers/{$offer->_id}/accept")
        ->assertOk()
        ->assertJsonPath('message', 'Offer accepted successfully');

    $application = Application::where('user_id', (string) $this->seeker->_id)->first();
    expect($application->resume)->toBe('https://cloudinary.example/cv.pdf')
        ->and($application->cover_letter)->toBe('Offer acceptance letter.');
});

// ── Access control ─────────────────────────────────────────────────────

test('an unauthenticated user cannot save a cover letter or delete a resume', function () {
    $this->putJson('/api/job-seeker/cover-letter', ['cover_letter' => 'test'])->assertUnauthorized();
    $this->deleteJson('/api/job-seeker/resume')->assertUnauthorized();
});

test('an employer cannot use the seeker cover letter endpoints', function () {
    $this->withToken(tokenFor('employer'))
        ->putJson('/api/job-seeker/cover-letter', ['cover_letter' => 'test'])
        ->assertForbidden();
});
