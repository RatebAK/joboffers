<?php

// ============================================================
// DO NOT DELETE — Tests for CV/resume upload endpoints.
// Covers: resume upload (no analysis), upload-and-analyze with
// mocked AI service (success, 422 parse failure, 502 service
// unavailable), file type validation, and auth guards.
// ============================================================

use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\CvAnalysisService;
use App\Exceptions\CvAnalysisException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ── Helpers ───────────────────────────────────────────────────

function cvSeeker(): array
{
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);
    return [$seeker, $token];
}

// ── POST /api/job-seeker/resume/upload ────────────────────────

test('seeker can upload a resume file', function () {
    Storage::fake('public');
    [$seeker, $token] = cvSeeker();

    $file = UploadedFile::fake()->create('my_resume.pdf', 200, 'application/pdf');

    $response = $this->withToken($token)->postJson('/api/job-seeker/resume/upload', [
        'resume' => $file,
    ]);

    $response->assertStatus(200)
             ->assertJsonStructure(['message', 'resume_url'])
             ->assertJsonPath('message', 'Resume uploaded successfully');

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('resume upload stores file path on profile', function () {
    Storage::fake('public');
    [$seeker, $token] = cvSeeker();

    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');
    $this->withToken($token)->postJson('/api/job-seeker/resume/upload', ['resume' => $file])
         ->assertStatus(200);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->resume)->not->toBeNull();

    $profile->delete();
    $seeker->delete();
});

test('resume upload accepts docx files', function () {
    Storage::fake('public');
    [$seeker, $token] = cvSeeker();

    $file = UploadedFile::fake()->create('resume.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload', ['resume' => $file])
         ->assertStatus(200);

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('resume upload rejects invalid file types', function () {
    Storage::fake('public');
    [$seeker, $token] = cvSeeker();

    $file = UploadedFile::fake()->create('resume.exe', 100, 'application/octet-stream');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload', ['resume' => $file])
         ->assertStatus(422)
         ->assertJsonStructure(['errors' => ['resume']]);

    $seeker->delete();
});

test('resume upload requires a file', function () {
    [, $token] = cvSeeker();

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload', [])
         ->assertStatus(422)
         ->assertJsonStructure(['errors' => ['resume']]);
});

test('unauthenticated user cannot upload resume', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $this->postJson('/api/job-seeker/resume/upload', ['resume' => $file])
         ->assertStatus(401);
});

test('employer cannot upload resume to job seeker endpoint', function () {
    Storage::fake('public');
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);
    $file     = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload', ['resume' => $file])
         ->assertStatus(403);

    $employer->delete();
});

// ── POST /api/job-seeker/resume/upload-and-analyze ────────────

test('upload-and-analyze succeeds and populates ai fields', function () {
    Storage::fake('public');
    [$seeker, $token] = cvSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name'         => 'Jane AI',
        'email'             => 'jane@ai.com',
        'phone'             => '+1234567890',
        'location'          => 'Beirut, Lebanon',
        'summary'           => 'Experienced developer.',
        'skills'            => ['PHP', 'Laravel'],
        'work_history'      => [],
        'projects'          => [],
        'ats_score'         => 88,
        'detected_language' => 'en',
    ]);

    $file = UploadedFile::fake()->create('cv.pdf', 500, 'application/pdf');

    $response = $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file,
    ]);

    $response->assertStatus(200)
             ->assertJsonStructure(['profile'])
             ->assertJsonPath('profile.ats_score', 88)
             ->assertJsonPath('profile.ai_full_name', 'Jane AI');

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->ai_skills)->toContain('PHP');
    expect($profile->ai_skills)->toContain('Laravel');

    $profile->delete();
    $seeker->delete();
});

test('upload-and-analyze returns 422 when AI cannot parse CV', function () {
    Storage::fake('public');
    [$seeker, $token] = cvSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andThrow(
        new CvAnalysisException('CV content could not be parsed.', 422)
    );

    $file = UploadedFile::fake()->create('bad_cv.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file,
    ])->assertStatus(422)
      ->assertJsonPath('message', 'CV analysis failed');

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('upload-and-analyze returns 502 when AI service is unavailable', function () {
    Storage::fake('public');
    [$seeker, $token] = cvSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andThrow(
        new CvAnalysisException('Service unavailable.', 503)
    );

    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file,
    ])->assertStatus(502)
      ->assertJsonPath('message', 'CV analysis service unavailable');

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('upload-and-analyze requires a cv file', function () {
    [, $token] = cvSeeker();

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [])
         ->assertStatus(422)
         ->assertJsonStructure(['errors' => ['cv']]);
});

test('upload-and-analyze rejects non-document file types', function () {
    Storage::fake('public');
    [$seeker, $token] = cvSeeker();

    $file = UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file,
    ])->assertStatus(422)
      ->assertJsonStructure(['errors' => ['cv']]);

    $seeker->delete();
});

test('unauthenticated user cannot upload-and-analyze', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $this->postJson('/api/job-seeker/resume/upload-and-analyze', ['cv' => $file])
         ->assertStatus(401);
});
