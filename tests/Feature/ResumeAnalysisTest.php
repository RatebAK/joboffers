<?php

// ============================================================
// Tests for resume analysis behavior after changes
// Verifies that both upload endpoints analyze resumes
// ============================================================

use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\CvAnalysisService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// ── Helpers ───────────────────────────────────────────────────

function analysisSeeker(): array
{
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);
    return [$seeker, $token];
}

// ── Tests ────────────────────────────────────────────────────

test('upload-resume endpoint analyzes resume and populates ai fields', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = analysisSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'John Doe',
        'email' => 'john@example.com',
        'phone' => '+1234567890',
        'location' => 'New York, USA',
        'summary' => 'Experienced software developer',
        'skills' => ['PHP', 'Laravel', 'JavaScript'],
        'work_history' => [
            ['company' => 'Tech Corp', 'role' => 'Senior Developer', 'duration' => '2020-2024']
        ],
        'education_history' => [
            ['institution' => 'University', 'degree' => 'BS Computer Science', 'year' => '2016-2020']
        ],
        'languages' => ['English', 'Spanish'],
        'projects' => ['E-commerce Platform', 'CRM System'],
        'linkedin' => 'https://linkedin.com/in/johndoe',
        'ai_overall_evaluation' => 'Strong candidate with relevant experience',
        'ats_score' => 85,
    ]);

    $file = UploadedFile::fake()->create('resume.pdf', 200, 'application/pdf');

    $response = $this->withToken($token)->postJson('/api/job-seeker/resume/upload', [
        'resume' => $file,
    ]);

    $response->assertStatus(200)
             ->assertJsonStructure(['message', 'resume_url', 'profile'])
             ->assertJsonPath('message', 'Resume uploaded and analyzed successfully')
             ->assertJsonPath('profile.ai_full_name', 'John Doe')
             ->assertJsonPath('profile.ai_email', 'john@example.com')
             ->assertJsonPath('profile.ats_score', 85)
             ->assertJsonPath('profile.ai_skills', ['PHP', 'Laravel', 'JavaScript']);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->resume)->not->toBeNull();
    expect($profile->cv_file_path)->not->toBeNull();
    expect($profile->resume)->toBe($profile->cv_file_path); // Should be the same file
    expect($profile->ai_analyzed_at)->not->toBeNull();

    $profile->delete();
    $seeker->delete();
});

test('upload-and-analyze endpoint works similarly with cv field', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = analysisSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Jane Smith',
        'ats_score' => 90,
        'skills' => ['React', 'TypeScript'],
    ]);

    $file = UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf');

    $response = $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file,
    ]);

    $response->assertStatus(200)
             ->assertJsonStructure(['message', 'resume_url', 'profile'])
             ->assertJsonPath('message', 'CV uploaded and analyzed successfully')
             ->assertJsonPath('profile.ai_full_name', 'Jane Smith')
             ->assertJsonPath('profile.ats_score', 90)
             ->assertJsonPath('profile.ai_skills', ['React', 'TypeScript']);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->resume)->not->toBeNull();
    expect($profile->cv_file_path)->not->toBeNull();
    expect($profile->resume)->toBe($profile->cv_file_path);

    $profile->delete();
    $seeker->delete();
});

test('both endpoints store file in same location and clean up previous files', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = analysisSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->twice()->andReturn(
        ['full_name' => 'First CV', 'ats_score' => 80],
        ['full_name' => 'Second CV', 'ats_score' => 85]
    );

    // First upload via /upload
    $file1 = UploadedFile::fake()->create('first.pdf', 100, 'application/pdf');
    $response1 = $this->withToken($token)->postJson('/api/job-seeker/resume/upload', [
        'resume' => $file1,
    ]);
    $response1->assertStatus(200);
    
    $profile1 = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    $firstResumeUrl = $profile1->resume;
    $firstCvUrl = $profile1->cv_file_path;
    
    expect($firstResumeUrl)->toBe($firstCvUrl);
    expect($profile1->ai_full_name)->toBe('First CV');

    // Second upload via /upload-and-analyze (should replace first)
    $file2 = UploadedFile::fake()->create('second.pdf', 100, 'application/pdf');
    $response2 = $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file2,
    ]);
    $response2->assertStatus(200);
    
    $profile2 = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    $secondResumeUrl = $profile2->resume;
    $secondCvUrl = $profile2->cv_file_path;
    
    expect($secondResumeUrl)->toBe($secondCvUrl);
    expect($secondResumeUrl)->not->toBe($firstResumeUrl); // Should be different URLs
    expect($profile2->ai_full_name)->toBe('Second CV'); // Should have new data

    $profile2->delete();
    $seeker->delete();
});

test('delete-resume cleans up both resume and cv fields', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = analysisSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Test User',
        'ats_score' => 75,
    ]);

    // Upload
    $file = UploadedFile::fake()->create('test.pdf', 100, 'application/pdf');
    $this->withToken($token)->postJson('/api/job-seeker/resume/upload', ['resume' => $file])
         ->assertStatus(200);

    // Delete
    $this->withToken($token)->deleteJson('/api/job-seeker/resume')
         ->assertStatus(200)
         ->assertJsonPath('message', 'Resume deleted successfully');

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->resume)->toBeNull();
    expect($profile->resume_public_id)->toBeNull();
    expect($profile->cv_file_path)->toBeNull();
    expect($profile->cv_public_id)->toBeNull();
    // AI fields should remain (analysis data is still valid even without file)
    expect($profile->ai_full_name)->toBe('Test User');
    expect($profile->ats_score)->toBe(75);

    $profile->delete();
    $seeker->delete();
});

test('analysis failure prevents file storage', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = analysisSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andThrow(
        new \App\Exceptions\CvAnalysisException('CV content could not be parsed.', 422)
    );

    $file = UploadedFile::fake()->create('bad.pdf', 100, 'application/pdf');

    $response = $this->withToken($token)->postJson('/api/job-seeker/resume/upload', [
        'resume' => $file,
    ]);

    $response->assertStatus(422)
             ->assertJsonPath('message', 'Resume analysis failed');

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    // File should not be stored if analysis fails
    expect($profile->resume)->toBeNull();
    expect($profile->cv_file_path)->toBeNull();

    $seeker->delete();
});

test('user can have ai data without file after deletion', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = analysisSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Analyzed User',
        'ats_score' => 88,
        'skills' => ['Python', 'Django'],
    ]);

    // Upload and analyze
    $file = UploadedFile::fake()->create('withdata.pdf', 100, 'application/pdf');
    $this->withToken($token)->postJson('/api/job-seeker/resume/upload', ['resume' => $file])
         ->assertStatus(200);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->ai_full_name)->toBe('Analyzed User');
    expect($profile->ats_score)->toBe(88);
    expect($profile->ai_skills)->toBe(['Python', 'Django']);
    expect($profile->resume)->not->toBeNull();

    // Delete file but keep AI data
    $this->withToken($token)->deleteJson('/api/job-seeker/resume')
         ->assertStatus(200);

    $profile->refresh();
    expect($profile->resume)->toBeNull();
    expect($profile->cv_file_path)->toBeNull();
    // AI data should remain
    expect($profile->ai_full_name)->toBe('Analyzed User');
    expect($profile->ats_score)->toBe(88);
    expect($profile->ai_skills)->toBe(['Python', 'Django']);

    $profile->delete();
    $seeker->delete();
});