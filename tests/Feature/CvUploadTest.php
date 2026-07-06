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
    Storage::fake('cloudinary');
    [$seeker, $token] = cvSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Test User',
        'ats_score' => 75,
    ]);

    $file = UploadedFile::fake()->create('my_resume.pdf', 200, 'application/pdf');

    $response = $this->withToken($token)->postJson('/api/job-seeker/resume/upload', [
        'resume' => $file,
    ]);

    $response->assertStatus(200)
             ->assertJsonStructure(['message', 'resume_url', 'profile'])
             ->assertJsonPath('message', 'Resume uploaded and analyzed successfully');

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('resume upload stores file path on profile', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = cvSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Test User',
        'ats_score' => 75,
    ]);

    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');
    $this->withToken($token)->postJson('/api/job-seeker/resume/upload', ['resume' => $file])
         ->assertStatus(200);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->resume)->not->toBeNull();
    expect($profile->cv_file_path)->not->toBeNull();

    $profile->delete();
    $seeker->delete();
});

test('resume upload accepts docx files', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = cvSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Test User',
        'ats_score' => 75,
    ]);

    $file = UploadedFile::fake()->create('resume.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload', ['resume' => $file])
         ->assertStatus(200);

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('resume upload rejects invalid file types', function () {
    Storage::fake('cloudinary');
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
    Storage::fake('cloudinary');
    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $this->postJson('/api/job-seeker/resume/upload', ['resume' => $file])
         ->assertStatus(401);
});

test('employer cannot upload resume to job seeker endpoint', function () {
    Storage::fake('cloudinary');
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);
    $file     = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload', ['resume' => $file])
         ->assertStatus(403);

    $employer->delete();
});

// ── POST /api/job-seeker/resume/upload-and-analyze ────────────

test('upload-and-analyze succeeds and populates ai fields', function () {
    Storage::fake('cloudinary');
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
        'education_history' => [],
        'languages'         => ['English', 'Arabic'],
        'projects'          => [],
        'linkedin'          => 'https://linkedin.com/in/janeai',
        'github'            => 'https://github.com/janeai',
        'ai_overall_evaluation' => 'Strong candidate with relevant experience.',
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
    expect($profile->ai_languages)->toContain('English');
    expect($profile->ai_languages)->toContain('Arabic');

    $profile->delete();
    $seeker->delete();
});

test('upload-and-analyze returns 422 when AI cannot parse CV', function () {
    Storage::fake('cloudinary');
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
    Storage::fake('cloudinary');
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
    Storage::fake('cloudinary');
    [$seeker, $token] = cvSeeker();

    $file = UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file,
    ])->assertStatus(422)
      ->assertJsonStructure(['errors' => ['cv']]);

    $seeker->delete();
});

test('unauthenticated user cannot upload-and-analyze', function () {
    Storage::fake('cloudinary');
    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $this->postJson('/api/job-seeker/resume/upload-and-analyze', ['cv' => $file])
         ->assertStatus(401);
});

// ── New field mapping tests ───────────────────────────────────

test('upload-and-analyze maps education_history correctly', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = cvSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Test User',
        'education_history' => [
            [
                'institution' => 'Syrian Virtual University',
                'degree' => 'BS IT-engineering',
                'year' => '2021-2024',
            ],
        ],
        'skills' => [],
        'work_history' => [],
        'ats_score' => 75,
    ]);

    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file,
    ])->assertStatus(200);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->ai_education_history)->toBeArray();
    expect($profile->ai_education_history[0]['institution'] ?? null)->toBe('Syrian Virtual University');
    expect($profile->ai_education_history[0]['degree'] ?? null)->toBe('BS IT-engineering');

    $profile->delete();
    $seeker->delete();
});

test('upload-and-analyze maps languages correctly', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = cvSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Test User',
        'languages' => ['English', 'Arabic', 'French'],
        'skills' => [],
        'work_history' => [],
        'ats_score' => 80,
    ]);

    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file,
    ])->assertStatus(200);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->ai_languages)->toBeArray();
    expect($profile->ai_languages)->toHaveCount(3);
    expect($profile->ai_languages)->toContain('English');
    expect($profile->ai_languages)->toContain('Arabic');

    $profile->delete();
    $seeker->delete();
});

test('upload-and-analyze maps social links correctly', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = cvSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Test User',
        'linkedin' => 'https://linkedin.com/in/testuser',
        'github' => 'https://github.com/testuser',
        'skills' => [],
        'work_history' => [],
        'ats_score' => 85,
    ]);

    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file,
    ])->assertStatus(200);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->ai_social_links)->toBeArray();
    expect($profile->ai_social_links['linkedin'] ?? null)->toBe('https://linkedin.com/in/testuser');
    expect($profile->ai_social_links['github'] ?? null)->toBe('https://github.com/testuser');

    $profile->delete();
    $seeker->delete();
});

test('upload-and-analyze maps work_history correctly', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = cvSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Test User',
        'work_history' => [
            [
                'company' => 'Freelance',
                'role' => 'Web developer',
                'duration' => 'Mar 2024 – Present',
                'description' => 'Built web applications.',
            ],
            [
                'company' => 'Remostart',
                'role' => 'Frontend developer',
                'duration' => 'Aug 2023 - Mar 2024',
                'description' => 'Developed React applications.',
            ],
        ],
        'skills' => [],
        'ats_score' => 90,
    ]);

    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file,
    ])->assertStatus(200);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->ai_work_history)->toBeArray();
    expect($profile->ai_work_history)->toHaveCount(2);
    expect($profile->ai_work_history[0]['company'] ?? null)->toBe('Freelance');
    expect($profile->ai_work_history[1]['company'] ?? null)->toBe('Remostart');

    $profile->delete();
    $seeker->delete();
});

test('upload-and-analyze maps projects correctly', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = cvSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Test User',
        'projects' => [
            'VIP Honey De',
            'E-commerce Website',
            'Smart Transport Optimizer',
        ],
        'skills' => [],
        'work_history' => [],
        'ats_score' => 85,
    ]);

    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file,
    ])->assertStatus(200);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->ai_projects)->toBeArray();
    expect($profile->ai_projects)->toHaveCount(3);
    expect($profile->ai_projects)->toContain('VIP Honey De');
    expect($profile->ai_projects)->toContain('E-commerce Website');

    $profile->delete();
    $seeker->delete();
});

test('upload-and-analyze handles missing optional fields gracefully', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = cvSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Minimal User',
        'ats_score' => 70,
        // No other fields provided
    ]);

    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $response = $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file,
    ]);

    $response->assertStatus(200)
             ->assertJsonPath('profile.ai_full_name', 'Minimal User')
             ->assertJsonPath('profile.ats_score', 70);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->ai_skills)->toBeArray();
    expect($profile->ai_skills)->toHaveCount(0);
    expect($profile->ai_languages)->toBeArray();
    expect($profile->ai_languages)->toHaveCount(0);

    $profile->delete();
    $seeker->delete();
});

test('upload-and-analyze stores ai_analyzed_at timestamp', function () {
    Storage::fake('cloudinary');
    [$seeker, $token] = cvSeeker();

    $mock = $this->mock(CvAnalysisService::class);
    $mock->shouldReceive('analyze')->once()->andReturn([
        'full_name' => 'Test User',
        'ats_score' => 80,
    ]);

    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/resume/upload-and-analyze', [
        'cv' => $file,
    ])->assertStatus(200);

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->ai_analyzed_at)->not->toBeNull();
    expect($profile->ai_analyzed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);

    $profile->delete();
    $seeker->delete();
});
