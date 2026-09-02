<?php

// =============================================================================
// ResumeStorageTest
//
// Verifies resume uploads record the metadata needed to serve and later delete
// the file, that a replaced resume is deleted first, that stale AI results are
// cleared, and that storage failures surface as a clear error (not an opaque
// AI parsing error). DocumentUploadService and CvAnalysisService are mocked.
// =============================================================================

use App\Exceptions\DocumentUploadException;
use App\Models\JobSeekerProfile;
use App\Services\CvAnalysisService;
use App\Services\DocumentUploadService;
use App\Services\StoredDocument;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    [$this->seeker, $this->token] = userWithToken('employee');
});

function storedDocument(): StoredDocument
{
    return new StoredDocument(
        url: 'https://res.cloudinary.com/demo/raw/upload/v1/job-seeker-cvs/cv_abc.txt',
        publicId: 'job-seeker-cvs/cv_abc.txt',
        resourceType: 'raw',
        mimeType: 'application/pdf',
        originalName: 'my_cv.pdf',
    );
}

function uploadCv(string $token): \Illuminate\Testing\TestResponse
{
    return test()->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'),
    ]);
}

test('uploading a cv records the Cloudinary resource type needed for deletion', function () {
    $uploader = $this->mock(DocumentUploadService::class);
    $uploader->shouldReceive('delete')->andReturnNull();
    $uploader->shouldReceive('upload')->once()->andReturn(storedDocument());
    $uploader->shouldReceive('assertDeliverable')->once()->andReturnNull();

    $this->mock(CvAnalysisService::class)
        ->shouldReceive('analyze')->once()
        ->andReturn(['full_name' => 'Tammam Mabroukeh', 'ats_score' => 71]);

    uploadCv($this->token)->assertOk();

    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile->resume_resource_type)->toBe('raw')
        ->and($profile->resume_public_id)->toBe('job-seeker-cvs/cv_abc.txt')
        ->and($profile->resume_original_name)->toBe('my_cv.pdf')
        ->and($profile->resume)->toBe($profile->cv_file_path)
        ->and($profile->analysis_status)->toBe('completed')
        ->and($profile->ats_score)->toBe(71);
});

test('a previous resume is deleted with its own resource type before the new one is stored', function () {
    JobSeekerProfile::create([
        'user_id'              => (string) $this->seeker->_id,
        'resume_public_id'     => 'job-seeker-cvs/old_cv.txt',
        'resume_resource_type' => 'raw',
        'cv_public_id'         => 'job-seeker-cvs/old_cv.txt',
    ]);

    $uploader = $this->mock(DocumentUploadService::class);
    $uploader->shouldReceive('delete')->once()->with('job-seeker-cvs/old_cv.txt', 'raw')->andReturnNull();
    $uploader->shouldReceive('upload')->once()->andReturn(storedDocument());
    $uploader->shouldReceive('assertDeliverable')->once()->andReturnNull();

    $this->mock(CvAnalysisService::class)->shouldReceive('analyze')->andReturn(['full_name' => 'Tammam']);

    uploadCv($this->token)->assertOk();
});

test('stale AI results are cleared when a new cv replaces an analysed one', function () {
    JobSeekerProfile::create([
        'user_id'      => (string) $this->seeker->_id,
        'ai_full_name' => 'Stale Name',
        'ai_skills'    => ['OldSkill'],
        'ats_score'    => 99,
    ]);

    $uploader = $this->mock(DocumentUploadService::class);
    $uploader->shouldReceive('delete')->andReturnNull();
    $uploader->shouldReceive('upload')->andReturn(storedDocument());
    $uploader->shouldReceive('assertDeliverable')->andReturnNull();

    $this->mock(CvAnalysisService::class)
        ->shouldReceive('analyze')
        ->andReturn(['full_name' => 'Fresh Name', 'skills' => ['Laravel'], 'ats_score' => 64]);

    uploadCv($this->token)->assertOk();

    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile->ai_full_name)->toBe('Fresh Name')
        ->and($profile->ai_skills)->toBe(['Laravel'])
        ->and($profile->ats_score)->toBe(64);
});

test('an undeliverable upload returns a storage error and never reaches the AI', function () {
    $uploader = $this->mock(DocumentUploadService::class);
    $uploader->shouldReceive('delete')->andReturnNull();
    $uploader->shouldReceive('upload')->once()->andReturn(storedDocument());
    $uploader->shouldReceive('assertDeliverable')->once()
        ->andThrow(DocumentUploadException::notDeliverable('https://res.cloudinary.com/x/cv.pdf', 401));

    // The AI service must never be reached for a file nobody can download.
    $this->mock(CvAnalysisService::class)->shouldNotReceive('analyze');

    $response = uploadCv($this->token)
        ->assertStatus(502)
        ->assertJsonPath('message', 'CV upload failed');

    expect($response->json('error'))->toContain('Allow delivery of PDF and ZIP files');
    expect(JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first()->analysis_status)->toBe('error');
});

test('deleting a resume keeps the AI analysis data', function () {
    JobSeekerProfile::create([
        'user_id'          => (string) $this->seeker->_id,
        'resume'           => 'https://res.cloudinary.com/x/cv.txt',
        'resume_public_id' => 'job-seeker-cvs/cv.txt',
        'ai_full_name'     => 'Analyzed User',
        'ai_skills'        => ['Python', 'Django'],
        'ats_score'        => 88,
    ]);

    $this->mock(DocumentUploadService::class)->shouldReceive('delete')->andReturnNull();

    $this->withToken($this->token)->deleteJson('/api/job-seeker/resume')->assertOk();

    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile->resume)->toBeNull()
        ->and($profile->ai_full_name)->toBe('Analyzed User')
        ->and($profile->ai_skills)->toBe(['Python', 'Django'])
        ->and($profile->ats_score)->toBe(88);
});

test('deleting a resume clears the stored file metadata', function () {
    JobSeekerProfile::create([
        'user_id'              => (string) $this->seeker->_id,
        'resume'               => 'https://res.cloudinary.com/demo/raw/upload/v1/cv.txt',
        'resume_public_id'     => 'job-seeker-cvs/cv.txt',
        'resume_resource_type' => 'raw',
        'cv_file_path'         => 'https://res.cloudinary.com/demo/raw/upload/v1/cv.txt',
        'cv_public_id'         => 'job-seeker-cvs/cv.txt',
    ]);

    $this->mock(DocumentUploadService::class)
        ->shouldReceive('delete')->once()->with('job-seeker-cvs/cv.txt', 'raw')->andReturnNull();

    $this->withToken($this->token)->deleteJson('/api/job-seeker/resume')->assertOk();

    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile->resume)->toBeNull()
        ->and($profile->resume_public_id)->toBeNull()
        ->and($profile->resume_resource_type)->toBeNull();
});
