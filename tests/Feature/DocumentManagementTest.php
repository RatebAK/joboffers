<?php

// ============================================================
// Tests for saved CV, resume, and cover letter management.
// Covers: GET profile documents block, resume upload/delete,
// default cover letter save/delete, fallback on apply, and
// offer-accept pulling profile documents automatically.
// ============================================================

use App\Models\Application;
use App\Models\DirectOffer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\CvAnalysisService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ── Helpers ───────────────────────────────────────────────────

function docSeeker(): array
{
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);
    return [$seeker, $token];
}

function docEmployer(): User
{
    return User::factory()->employer()->create();
}

function docJobPost(string $employerId): JobPost
{
    return JobPost::create([
        'title'        => 'Test Role',
        'description'  => 'Description.',
        'requirements' => 'Requirements.',
        'company_name' => 'Test Co',
        'job_type'     => 'full_time',
        'employer_id'  => $employerId,
        'is_active'    => true,
    ]);
}

// ── GET /api/job-seeker/profile — documents block ─────────────

test('profile response includes documents block', function () {
    [$seeker, $token] = docSeeker();

    $this->withToken($token)->getJson('/api/job-seeker/profile')
        ->assertStatus(200)
        ->assertJsonStructure(['documents' => [
            'cv_url',
            'cv_analyzed_at',
            'resume_url',
            'default_cover_letter',
        ]]);

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('documents block is null when no files uploaded yet', function () {
    [$seeker, $token] = docSeeker();

    $response = $this->withToken($token)->getJson('/api/job-seeker/profile')
        ->assertStatus(200);

    expect($response->json('documents.cv_url'))->toBeNull();
    expect($response->json('documents.resume_url'))->toBeNull();
    expect($response->json('documents.default_cover_letter'))->toBeNull();

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

// ── DELETE /api/job-seeker/resume ─────────────────────────────

test('seeker can delete their saved resume', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = docSeeker();

    // Mock the AI analysis service
    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Test User',
        'ats_score' => 75,
    ]);

    // Upload first
    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');
    $this->withToken($token)->postJson('/api/job-seeker/resume/upload', ['resume' => $file])
         ->assertStatus(200);

    // Now delete
    $this->withToken($token)->deleteJson('/api/job-seeker/resume')
         ->assertStatus(200)
         ->assertJsonPath('message', 'Resume deleted successfully');

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->resume)->toBeNull();
    expect($profile->resume_public_id)->toBeNull();
    expect($profile->cv_file_path)->toBeNull();
    expect($profile->cv_public_id)->toBeNull();

    $profile->delete();
    $seeker->delete();
});

test('deleting resume when none exists returns 404', function () {
    [$seeker, $token] = docSeeker();

    $this->withToken($token)->deleteJson('/api/job-seeker/resume')
         ->assertStatus(404)
         ->assertJsonPath('message', 'No resume found on your profile');

    $seeker->delete();
});

// ── PUT /api/job-seeker/cover-letter ─────────────────────────

test('seeker can save a default cover letter', function () {
    [$seeker, $token] = docSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/cover-letter', [
        'cover_letter' => 'I am excited to apply for this position.',
    ])->assertStatus(200)
      ->assertJsonPath('message', 'Default cover letter saved')
      ->assertJsonPath('default_cover_letter', 'I am excited to apply for this position.');

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->default_cover_letter)->toBe('I am excited to apply for this position.');

    $profile->delete();
    $seeker->delete();
});

test('seeker can update an existing default cover letter', function () {
    [$seeker, $token] = docSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/cover-letter', [
        'cover_letter' => 'First version.',
    ])->assertStatus(200);

    $this->withToken($token)->putJson('/api/job-seeker/cover-letter', [
        'cover_letter' => 'Updated version.',
    ])->assertStatus(200)
      ->assertJsonPath('default_cover_letter', 'Updated version.');

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->default_cover_letter)->toBe('Updated version.');

    $profile->delete();
    $seeker->delete();
});

test('saving cover letter requires content', function () {
    [, $token] = docSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/cover-letter', [])
         ->assertStatus(422)
         ->assertJsonStructure(['errors' => ['cover_letter']]);
});

test('cover letter is capped at 2000 characters', function () {
    [$seeker, $token] = docSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/cover-letter', [
        'cover_letter' => str_repeat('a', 2001),
    ])->assertStatus(422)
      ->assertJsonStructure(['errors' => ['cover_letter']]);

    $seeker->delete();
});

test('saved cover letter appears in profile documents block', function () {
    [$seeker, $token] = docSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/cover-letter', [
        'cover_letter' => 'My default letter.',
    ])->assertStatus(200);

    $this->withToken($token)->getJson('/api/job-seeker/profile')
         ->assertStatus(200)
         ->assertJsonPath('documents.default_cover_letter', 'My default letter.');

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

// ── DELETE /api/job-seeker/cover-letter ──────────────────────

test('seeker can delete their default cover letter', function () {
    [$seeker, $token] = docSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/cover-letter', [
        'cover_letter' => 'To be deleted.',
    ])->assertStatus(200);

    $this->withToken($token)->deleteJson('/api/job-seeker/cover-letter')
         ->assertStatus(200)
         ->assertJsonPath('message', 'Default cover letter deleted');

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->default_cover_letter)->toBeNull();

    $profile->delete();
    $seeker->delete();
});

test('deleting cover letter when none exists returns 404', function () {
    [$seeker, $token] = docSeeker();

    $this->withToken($token)->deleteJson('/api/job-seeker/cover-letter')
         ->assertStatus(404)
         ->assertJsonPath('message', 'No default cover letter found on your profile');

    $seeker->delete();
});

// ── Apply fallback: default cover letter ─────────────────────

test('apply uses default cover letter when none provided', function () {
    [$seeker, $token] = docSeeker();
    $employer = docEmployer();
    $job = docJobPost((string) $employer->_id);

    // Save a default cover letter on profile
    JobSeekerProfile::firstOrCreate(['user_id' => $seeker->_id], [])
        ->update(['default_cover_letter' => 'Auto cover letter.']);

    $response = $this->withToken($token)->postJson('/api/job-seeker/apply', [
        'job_post_id' => (string) $job->_id,
        // no cover_letter in request
    ])->assertStatus(201);

    $app = Application::where('user_id', $seeker->_id)
                      ->where('job_post_id', (string) $job->_id)
                      ->first();
    expect($app->cover_letter)->toBe('Auto cover letter.');

    Application::where('user_id', $seeker->_id)->delete();
    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $job->delete(); $employer->delete(); $seeker->delete();
});

test('explicit cover letter on apply overrides profile default', function () {
    [$seeker, $token] = docSeeker();
    $employer = docEmployer();
    $job = docJobPost((string) $employer->_id);

    JobSeekerProfile::firstOrCreate(['user_id' => $seeker->_id], [])
        ->update(['default_cover_letter' => 'Profile default.']);

    $response = $this->withToken($token)->postJson('/api/job-seeker/apply', [
        'job_post_id'  => (string) $job->_id,
        'cover_letter' => 'Custom per-application letter.',
    ])->assertStatus(201);

    $app = Application::where('user_id', $seeker->_id)
                      ->where('job_post_id', (string) $job->_id)
                      ->first();
    expect($app->cover_letter)->toBe('Custom per-application letter.');

    Application::where('user_id', $seeker->_id)->delete();
    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $job->delete(); $employer->delete(); $seeker->delete();
});

test('apply uses profile cv when no resume uploaded per-application', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = docSeeker();
    $employer = docEmployer();
    $job = docJobPost((string) $employer->_id);

    JobSeekerProfile::firstOrCreate(['user_id' => $seeker->_id], [])
        ->update(['cv_file_path' => 'https://cloudinary.example/cv.pdf']);

    $response = $this->withToken($token)->postJson('/api/job-seeker/apply', [
        'job_post_id' => (string) $job->_id,
    ])->assertStatus(201);

    $app = Application::where('user_id', $seeker->_id)
                      ->where('job_post_id', (string) $job->_id)
                      ->first();
    expect($app->resume)->toBe('https://cloudinary.example/cv.pdf');

    Application::where('user_id', $seeker->_id)->delete();
    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $job->delete(); $employer->delete(); $seeker->delete();
});

// ── DirectOffer accept pulls profile documents ────────────────

test('accepting offer auto-creates application with profile cv and cover letter', function () {
    [$seeker, $seekerToken] = docSeeker();
    $employer = docEmployer();
    $job = docJobPost((string) $employer->_id);

    JobSeekerProfile::firstOrCreate(['user_id' => $seeker->_id], [])
        ->update([
            'cv_file_path'         => 'https://cloudinary.example/cv.pdf',
            'default_cover_letter' => 'Offer acceptance letter.',
        ]);

    $offer = DirectOffer::create([
        'employer_id'   => $employer->_id,
        'job_seeker_id' => $seeker->_id,
        'job_post_id'   => $job->_id,
        'message'       => 'We want you.',
        'status'        => 'pending',
    ]);

    $this->withToken($seekerToken)->postJson("/api/job-seeker/offers/{$offer->_id}/accept")
         ->assertStatus(200)
         ->assertJsonPath('message', 'Offer accepted successfully');

    $app = Application::where('user_id', $seeker->_id)
                      ->where('job_post_id', (string) $job->_id)
                      ->first();

    expect($app)->not->toBeNull();
    expect($app->resume)->toBe('https://cloudinary.example/cv.pdf');
    expect($app->cover_letter)->toBe('Offer acceptance letter.');

    Application::where('user_id', $seeker->_id)->delete();
    DirectOffer::where('employer_id', $employer->_id)->delete();
    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $job->delete(); $employer->delete(); $seeker->delete();
});

// ── Auth guards ───────────────────────────────────────────────

test('unauthenticated user cannot save cover letter', function () {
    $this->putJson('/api/job-seeker/cover-letter', ['cover_letter' => 'test'])
         ->assertStatus(401);
});

test('unauthenticated user cannot delete resume', function () {
    $this->deleteJson('/api/job-seeker/resume')->assertStatus(401);
});

test('employer cannot access job seeker cover letter endpoints', function () {
    $employer = docEmployer();
    $token    = auth('api')->login($employer);

    $this->withToken($token)->putJson('/api/job-seeker/cover-letter', ['cover_letter' => 'test'])
         ->assertStatus(403);

    $employer->delete();
});
