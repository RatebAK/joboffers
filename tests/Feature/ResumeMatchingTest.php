<?php

// ============================================================
// Tests for AI resume-to-jobs matching endpoint.
// Job seekers upload CV and get matched job recommendations.
// ============================================================

use App\Models\User;
use App\Services\ResumeMatchingService;
use App\Exceptions\CvAnalysisException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function matchingSeeker(): array
{
    $seeker = User::factory()->employee()->create();
    $token = auth('api')->login($seeker);

    return [$seeker, $token];
}

// ── POST /api/job-seeker/match-resume-to-jobs ─────────────────

test('job seeker can match resume to jobs', function () {
    Storage::fake('public');
    [$seeker, $token] = matchingSeeker();

    $mock = $this->mock(ResumeMatchingService::class);
    $mock->shouldReceive('matchResumeToJobs')
        ->once()
        ->andReturn([
            'matches_found' => 25,
            'recommended_jobs' => [
                [
                    'job_id' => 'job_001',
                    'title' => 'Backend Python Developer',
                    'company' => 'TechStream Solutions',
                    'location' => 'Remote',
                    'matched_skills_count' => 4,
                    'match_percentage' => '66%',
                    'ats_compatibility_score' => '69%',
                ],
                [
                    'job_id' => 'job_002',
                    'title' => 'Data Scientist',
                    'company' => 'InsightData AI',
                    'location' => 'Damascus, Syria',
                    'matched_skills_count' => 3,
                    'match_percentage' => '50%',
                    'ats_compatibility_score' => '60%',
                ],
            ],
        ]);

    $file = UploadedFile::fake()->create('resume.pdf', 500, 'application/pdf');

    $response = $this->withToken($token)->postJson('/api/job-seeker/match-resume-to-jobs', [
        'resume' => $file,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'matches_found',
            'recommended_jobs' => [
                '*' => [
                    'job_id',
                    'title',
                    'company',
                    'location',
                    'matched_skills_count',
                    'match_percentage',
                    'ats_compatibility_score',
                    'job_url',
                    'exists_in_db',
                ],
            ],
        ])
        ->assertJsonPath('matches_found', 25)
        ->assertJsonPath('recommended_jobs.0.title', 'Backend Python Developer')
        ->assertJsonPath('recommended_jobs.0.match_percentage', '66%');

    $seeker->delete();
});

test('resume matching returns correct structure', function () {
    Storage::fake('public');
    [$seeker, $token] = matchingSeeker();

    $mock = $this->mock(ResumeMatchingService::class);
    $mock->shouldReceive('matchResumeToJobs')
        ->once()
        ->andReturn([
            'matches_found' => 5,
            'recommended_jobs' => [
                [
                    'job_id' => 'job_python_fastapi_001',
                    'title' => 'Backend Web Engineer',
                    'company' => 'Alpha Tech Solutions',
                    'location' => 'Remote',
                    'matched_skills_count' => 4,
                    'match_percentage' => '66%',
                    'ats_compatibility_score' => '69%',
                ],
            ],
        ]);

    $file = UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf');

    $response = $this->withToken($token)->postJson('/api/job-seeker/match-resume-to-jobs', [
        'resume' => $file,
    ]);

    $response->assertStatus(200);
    expect($response->json())->toHaveKeys(['matches_found', 'recommended_jobs']);
    expect($response->json('recommended_jobs.0'))->toHaveKeys([
        'job_id',
        'title',
        'company',
        'location',
        'matched_skills_count',
        'match_percentage',
        'ats_compatibility_score',
        'job_url',
        'exists_in_db',
    ]);

    $seeker->delete();
});

test('resume matching requires resume file', function () {
    [, $token] = matchingSeeker();

    $this->withToken($token)->postJson('/api/job-seeker/match-resume-to-jobs', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['resume']]);
});

test('resume matching accepts pdf files', function () {
    Storage::fake('public');
    [$seeker, $token] = matchingSeeker();

    $mock = $this->mock(ResumeMatchingService::class);
    $mock->shouldReceive('matchResumeToJobs')
        ->once()
        ->andReturn(['matches_found' => 0, 'recommended_jobs' => []]);

    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/match-resume-to-jobs', [
        'resume' => $file,
    ])->assertStatus(200);

    $seeker->delete();
});

test('resume matching accepts docx files', function () {
    Storage::fake('public');
    [$seeker, $token] = matchingSeeker();

    $mock = $this->mock(ResumeMatchingService::class);
    $mock->shouldReceive('matchResumeToJobs')
        ->once()
        ->andReturn(['matches_found' => 0, 'recommended_jobs' => []]);

    $file = UploadedFile::fake()->create('resume.docx', 100, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $this->withToken($token)->postJson('/api/job-seeker/match-resume-to-jobs', [
        'resume' => $file,
    ])->assertStatus(200);

    $seeker->delete();
});

test('resume matching rejects invalid file types', function () {
    Storage::fake('public');
    [, $token] = matchingSeeker();

    $file = UploadedFile::fake()->create('resume.txt', 100, 'text/plain');

    $this->withToken($token)->postJson('/api/job-seeker/match-resume-to-jobs', [
        'resume' => $file,
    ])->assertStatus(422)
      ->assertJsonStructure(['errors' => ['resume']]);
});

test('resume matching rejects files over 10MB', function () {
    Storage::fake('public');
    [, $token] = matchingSeeker();

    $file = UploadedFile::fake()->create('large.pdf', 11000, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/match-resume-to-jobs', [
        'resume' => $file,
    ])->assertStatus(422)
      ->assertJsonStructure(['errors' => ['resume']]);
});

test('resume matching returns 422 when service fails', function () {
    Storage::fake('public');
    [$seeker, $token] = matchingSeeker();

    $mock = $this->mock(ResumeMatchingService::class);
    $mock->shouldReceive('matchResumeToJobs')
        ->once()
        ->andThrow(new CvAnalysisException('Invalid resume format', 422));

    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/match-resume-to-jobs', [
        'resume' => $file,
    ])->assertStatus(422)
      ->assertJsonPath('message', 'Resume matching failed');

    $seeker->delete();
});

test('resume matching returns 502 when service unavailable', function () {
    Storage::fake('public');
    [$seeker, $token] = matchingSeeker();

    $mock = $this->mock(ResumeMatchingService::class);
    $mock->shouldReceive('matchResumeToJobs')
        ->once()
        ->andThrow(new CvAnalysisException('Service unavailable', 502));

    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/match-resume-to-jobs', [
        'resume' => $file,
    ])->assertStatus(502)
      ->assertJsonPath('message', 'Resume matching service unavailable');

    $seeker->delete();
});

test('unauthenticated user cannot match resume', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->postJson('/api/job-seeker/match-resume-to-jobs', [
        'resume' => $file,
    ])->assertStatus(401);
});

test('employer cannot match resume on job seeker endpoint', function () {
    Storage::fake('public');
    $employer = User::factory()->employer()->create();
    $token = auth('api')->login($employer);
    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $this->withToken($token)->postJson('/api/job-seeker/match-resume-to-jobs', [
        'resume' => $file,
    ])->assertStatus(403);

    $employer->delete();
});

test('resume matching handles empty results', function () {
    Storage::fake('public');
    [$seeker, $token] = matchingSeeker();

    $mock = $this->mock(ResumeMatchingService::class);
    $mock->shouldReceive('matchResumeToJobs')
        ->once()
        ->andReturn([
            'matches_found' => 0,
            'recommended_jobs' => [],
        ]);

    $file = UploadedFile::fake()->create('resume.pdf', 100, 'application/pdf');

    $response = $this->withToken($token)->postJson('/api/job-seeker/match-resume-to-jobs', [
        'resume' => $file,
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('matches_found', 0)
        ->assertJsonPath('recommended_jobs', []);

    $seeker->delete();
});
