<?php

// ============================================================
// DO NOT DELETE — Tests that resume uploads record everything
// needed to serve and later delete the file, and that storage
// failures surface as a clear API error instead of an opaque
// "could not extract text" from the AI service.
// ============================================================

use App\Exceptions\DocumentUploadException;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\CvAnalysisService;
use App\Services\DocumentUploadService;
use App\Services\StoredDocument;
use Illuminate\Http\UploadedFile;

// ── Helpers ───────────────────────────────────────────────────

function storageSeeker(): array
{
    $seeker = User::factory()->employee()->create();

    return [$seeker, auth('api')->login($seeker)];
}

function fakeStoredDocument(string $url = 'https://res.cloudinary.com/demo/raw/upload/v1/job-seeker-cvs/cv_abc.txt'): StoredDocument
{
    return new StoredDocument(
        url: $url,
        publicId: 'job-seeker-cvs/cv_abc.txt',
        resourceType: 'raw',
        mimeType: 'application/pdf',
        originalName: 'my_cv.pdf',
    );
}

function cleanUp(User $seeker): void
{
    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
}

// ── Successful upload ─────────────────────────────────────────

test('uploading a cv records the cloudinary resource type needed for deletion', function () {
    [$seeker, $token] = storageSeeker();

    $uploader = $this->mock(DocumentUploadService::class);
    $uploader->shouldReceive('delete')->andReturnNull();
    $uploader->shouldReceive('upload')->once()->andReturn(fakeStoredDocument());
    $uploader->shouldReceive('assertDeliverable')->once()->andReturnNull();

    $this->mock(CvAnalysisService::class)
        ->shouldReceive('analyze')
        ->once()
        ->andReturn(['full_name' => 'Tammam Mabroukeh', 'ats_score' => 71]);

    $response = $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => UploadedFile::fake()->create('my_cv.pdf', 100, 'application/pdf'),
    ]);

    $response->assertStatus(200);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();

    expect($profile->resume_resource_type)->toBe('raw')
        ->and($profile->resume_public_id)->toBe('job-seeker-cvs/cv_abc.txt')
        ->and($profile->resume_original_name)->toBe('my_cv.pdf')
        ->and($profile->resume)->toBe($profile->cv_file_path)
        ->and($profile->analysis_status)->toBe('completed')
        ->and($profile->ats_score)->toBe(71);

    cleanUp($seeker);
});

test('a previous resume is deleted with its own resource type before the new one is stored', function () {
    [$seeker, $token] = storageSeeker();

    $profile = JobSeekerProfile::create([
        'user_id'              => $seeker->_id,
        'resume_public_id'     => 'job-seeker-cvs/old_cv.txt',
        'resume_resource_type' => 'raw',
        'cv_public_id'         => 'job-seeker-cvs/old_cv.txt',
    ]);

    $uploader = $this->mock(DocumentUploadService::class);
    $uploader->shouldReceive('delete')
        ->once()
        ->with('job-seeker-cvs/old_cv.txt', 'raw')
        ->andReturnNull();
    $uploader->shouldReceive('upload')->once()->andReturn(fakeStoredDocument());
    $uploader->shouldReceive('assertDeliverable')->once()->andReturnNull();

    $this->mock(CvAnalysisService::class)
        ->shouldReceive('analyze')
        ->andReturn(['full_name' => 'Tammam Mabroukeh']);

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => UploadedFile::fake()->create('new_cv.pdf', 100, 'application/pdf'),
    ])->assertStatus(200);

    cleanUp($seeker);
});

test('stale ai results are cleared when a new cv replaces an analysed one', function () {
    [$seeker, $token] = storageSeeker();

    JobSeekerProfile::create([
        'user_id'      => $seeker->_id,
        'ai_full_name' => 'Stale Name',
        'ai_skills'    => ['OldSkill'],
        'ats_score'    => 99,
    ]);

    $uploader = $this->mock(DocumentUploadService::class);
    $uploader->shouldReceive('delete')->andReturnNull();
    $uploader->shouldReceive('upload')->andReturn(fakeStoredDocument());
    $uploader->shouldReceive('assertDeliverable')->andReturnNull();

    $this->mock(CvAnalysisService::class)
        ->shouldReceive('analyze')
        ->andReturn(['full_name' => 'Fresh Name', 'skills' => ['Laravel'], 'ats_score' => 64]);

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
    ])->assertStatus(200);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();

    expect($profile->ai_full_name)->toBe('Fresh Name')
        ->and($profile->ai_skills)->toBe(['Laravel'])
        ->and($profile->ats_score)->toBe(64);

    cleanUp($seeker);
});

// ── Storage failure ───────────────────────────────────────────

test('an undeliverable upload returns a storage error instead of an ai parsing error', function () {
    [$seeker, $token] = storageSeeker();

    $uploader = $this->mock(DocumentUploadService::class);
    $uploader->shouldReceive('delete')->andReturnNull();
    $uploader->shouldReceive('upload')->once()->andReturn(fakeStoredDocument());
    $uploader->shouldReceive('assertDeliverable')
        ->once()
        ->andThrow(DocumentUploadException::notDeliverable('https://res.cloudinary.com/x/cv.pdf', 401));

    // The AI service must never be reached for a file nobody can download.
    $this->mock(CvAnalysisService::class)->shouldNotReceive('analyze');

    $response = $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
    ]);

    $response->assertStatus(502)
        ->assertJsonPath('message', 'CV upload failed');

    expect($response->json('error'))->toContain('Allow delivery of PDF and ZIP files');

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->analysis_status)->toBe('error');

    cleanUp($seeker);
});

// ── Deletion endpoint ─────────────────────────────────────────

test('deleting a resume clears the stored file metadata', function () {
    [$seeker, $token] = storageSeeker();

    JobSeekerProfile::create([
        'user_id'              => $seeker->_id,
        'resume'               => 'https://res.cloudinary.com/demo/raw/upload/v1/cv.txt',
        'resume_public_id'     => 'job-seeker-cvs/cv.txt',
        'resume_resource_type' => 'raw',
        'cv_file_path'         => 'https://res.cloudinary.com/demo/raw/upload/v1/cv.txt',
        'cv_public_id'         => 'job-seeker-cvs/cv.txt',
    ]);

    $uploader = $this->mock(DocumentUploadService::class);
    $uploader->shouldReceive('delete')
        ->once()
        ->with('job-seeker-cvs/cv.txt', 'raw')
        ->andReturnNull();

    $this->withToken($token)
        ->deleteJson('/api/job-seeker/resume')
        ->assertStatus(200);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();

    expect($profile->resume)->toBeNull()
        ->and($profile->resume_public_id)->toBeNull()
        ->and($profile->resume_resource_type)->toBeNull();

    cleanUp($seeker);
});
