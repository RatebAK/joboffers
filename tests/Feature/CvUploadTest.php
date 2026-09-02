<?php

// =============================================================================
// CvUploadTest — resume upload endpoints.
//   POST /api/job-seeker/resume/upload
//   POST /api/job-seeker/resume/upload-and-analyze
//
// The Cloudinary upload (DocumentUploadService) and the AI analysis
// (CvAnalysisService) are mocked, so these tests never touch the network.
// =============================================================================

use App\Exceptions\CvAnalysisException;
use App\Models\JobSeekerProfile;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    [$this->seeker, $this->token] = userWithToken('employee');
});

/** The current seeker's profile. */
function cvProfile(): ?JobSeekerProfile
{
    return JobSeekerProfile::where('user_id', (string) test()->seeker->_id)->first();
}

// ── POST /api/job-seeker/resume/upload ───────────────────────────────────

test('a seeker can upload a resume', function () {
    fakeDocumentUpload();
    fakeCvAnalysis(['full_name' => 'Test User', 'ats_score' => 75]);

    $this->withToken($this->token)
        ->postJson('/api/job-seeker/resume/upload', ['resume' => UploadedFile::fake()->create('cv.pdf', 200, 'application/pdf')])
        ->assertOk()
        ->assertJsonStructure(['message', 'resume_url', 'profile']);

    $profile = cvProfile();
    expect($profile->resume)->not->toBeNull()
        ->and($profile->cv_file_path)->not->toBeNull();
});

test('resume upload accepts pdf and docx files', function (string $name, string $mime) {
    fakeDocumentUpload();
    fakeCvAnalysis(['full_name' => 'Test User', 'ats_score' => 70]);

    $this->withToken($this->token)
        ->postJson('/api/job-seeker/resume/upload', ['resume' => UploadedFile::fake()->create($name, 100, $mime)])
        ->assertOk();
})->with([
    'pdf'  => ['cv.pdf', 'application/pdf'],
    'docx' => ['cv.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
]);

test('resume upload rejects an invalid file type', function () {
    $this->withToken($this->token)
        ->postJson('/api/job-seeker/resume/upload', ['resume' => UploadedFile::fake()->create('cv.exe', 100, 'application/octet-stream')])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['resume']]);
});

test('resume upload requires a file', function () {
    $this->withToken($this->token)
        ->postJson('/api/job-seeker/resume/upload', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['resume']]);
});

test('an unauthenticated user cannot upload a resume', function () {
    $this->postJson('/api/job-seeker/resume/upload', ['resume' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
        ->assertUnauthorized();
});

test('an employer cannot use the job seeker resume endpoint', function () {
    $this->withToken(tokenFor('employer'))
        ->postJson('/api/job-seeker/resume/upload', ['resume' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
        ->assertForbidden();
});

// ── POST /api/job-seeker/resume/upload-and-analyze ───────────────────────

test('upload-and-analyze populates AI fields on success', function () {
    fakeDocumentUpload();
    fakeCvAnalysis([
        'full_name' => 'Jane AI',
        'skills'    => ['PHP', 'Laravel'],
        'languages' => ['English', 'Arabic'],
        'ats_score' => 88,
    ]);

    $this->withToken($this->token)
        ->postJson('/api/job-seeker/resume/upload-and-analyze', ['cv' => UploadedFile::fake()->create('cv.pdf', 500, 'application/pdf')])
        ->assertOk()
        ->assertJsonPath('profile.ats_score', 88)
        ->assertJsonPath('profile.ai_full_name', 'Jane AI');

    $profile = cvProfile();
    expect($profile->ai_skills)->toContain('PHP')->toContain('Laravel')
        ->and($profile->ai_languages)->toContain('English')->toContain('Arabic')
        ->and($profile->ai_analyzed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

test('upload-and-analyze maps each structured AI field', function (string $field, array $analysis, callable $assert) {
    fakeDocumentUpload();
    fakeCvAnalysis(array_merge(['full_name' => 'Test User', 'ats_score' => 80], $analysis));

    $this->withToken($this->token)
        ->postJson('/api/job-seeker/resume/upload-and-analyze', ['cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
        ->assertOk();

    $assert(cvProfile());
})->with([
    'education_history' => [
        'education_history',
        ['education_history' => [['institution' => 'Syrian Virtual University', 'degree' => 'BS IT', 'year' => '2021-2024']]],
        fn (JobSeekerProfile $p) => expect($p->ai_education_history[0]['institution'])->toBe('Syrian Virtual University'),
    ],
    'languages' => [
        'languages',
        ['languages' => ['English', 'Arabic', 'French']],
        fn (JobSeekerProfile $p) => expect($p->ai_languages)->toHaveCount(3)->toContain('French'),
    ],
    'social links' => [
        'social',
        ['linkedin' => 'https://linkedin.com/in/testuser', 'github' => 'https://github.com/testuser'],
        fn (JobSeekerProfile $p) => expect($p->ai_social_links['linkedin'])->toBe('https://linkedin.com/in/testuser'),
    ],
    'work history' => [
        'work_history',
        ['work_history' => [['company' => 'Freelance', 'role' => 'Dev'], ['company' => 'Remostart', 'role' => 'FE']]],
        fn (JobSeekerProfile $p) => expect($p->ai_work_history)->toHaveCount(2)->and($p->ai_work_history[0]['company'])->toBe('Freelance'),
    ],
    'projects' => [
        'projects',
        ['projects' => ['VIP Honey De', 'E-commerce Website']],
        fn (JobSeekerProfile $p) => expect($p->ai_projects)->toHaveCount(2)->toContain('VIP Honey De'),
    ],
]);

test('upload-and-analyze handles missing optional fields gracefully', function () {
    fakeDocumentUpload();
    fakeCvAnalysis(['full_name' => 'Minimal User', 'ats_score' => 70]);

    $this->withToken($this->token)
        ->postJson('/api/job-seeker/resume/upload-and-analyze', ['cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
        ->assertOk()
        ->assertJsonPath('profile.ai_full_name', 'Minimal User');

    $profile = cvProfile();
    expect($profile->ai_skills)->toBeArray()->toHaveCount(0)
        ->and($profile->ai_languages)->toBeArray()->toHaveCount(0);
});

test('upload-and-analyze returns 422 when the AI cannot parse the CV', function () {
    fakeDocumentUpload();
    fakeCvAnalysis(new CvAnalysisException('CV content could not be parsed.', 422));

    $this->withToken($this->token)
        ->postJson('/api/job-seeker/resume/upload-and-analyze', ['cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
        ->assertStatus(422)
        ->assertJsonPath('message', 'CV analysis failed');
});

test('upload-and-analyze returns 502 when the AI service is unavailable', function () {
    fakeDocumentUpload();
    fakeCvAnalysis(new CvAnalysisException('Service unavailable.', 503));

    $this->withToken($this->token)
        ->postJson('/api/job-seeker/resume/upload-and-analyze', ['cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
        ->assertStatus(502)
        ->assertJsonPath('message', 'CV analysis service unavailable');
});

test('upload-and-analyze requires a cv file', function () {
    $this->withToken($this->token)
        ->postJson('/api/job-seeker/resume/upload-and-analyze', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['cv']]);
});

test('upload-and-analyze rejects a non-document file', function () {
    $this->withToken($this->token)
        ->postJson('/api/job-seeker/resume/upload-and-analyze', ['cv' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg')])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['cv']]);
});

test('an unauthenticated user cannot upload-and-analyze', function () {
    $this->postJson('/api/job-seeker/resume/upload-and-analyze', ['cv' => UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf')])
        ->assertUnauthorized();
});
