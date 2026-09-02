<?php

// =============================================================================
// ResumeMatchingTest — GET /api/job-seeker/match-resume-to-jobs
//
// Uses the CV already stored on the seeker's profile to fetch AI-matched jobs.
// The ResumeMatchingService (external AI) is mocked; no file is uploaded here.
// =============================================================================

use App\Exceptions\CvAnalysisException;
use App\Services\ResumeMatchingService;

beforeEach(function () {
    [$this->seeker, $this->token] = userWithToken('employee');
});

/** Give the current seeker a profile with an analysed CV. */
function seekerWithCv(): void
{
    \App\Models\JobSeekerProfile::create([
        'user_id'      => (string) test()->seeker->_id,
        'cv_file_path' => 'https://cloudinary.example/cv.pdf',
    ]);
}

/** Mock the matching service to return the given payload. */
function fakeMatching(array $result): void
{
    test()->mock(ResumeMatchingService::class)
        ->shouldReceive('matchResumeToJobs')->once()->andReturn($result);
}

test('a seeker with a CV gets matched jobs', function () {
    seekerWithCv();
    fakeMatching([
        'matches_found' => 2,
        'jobs' => [
            ['_id' => 'a', 'title' => 'Senior Laravel Developer', 'matched_skills' => ['php', 'laravel'], 'matched_skills_score' => 3],
            ['_id' => 'b', 'title' => 'Backend Engineer', 'matched_skills' => ['php'], 'matched_skills_score' => 1],
        ],
    ]);

    $this->withToken($this->token)
        ->getJson('/api/job-seeker/match-resume-to-jobs')
        ->assertOk()
        ->assertJsonStructure(['matches_found', 'jobs'])
        ->assertJsonPath('matches_found', 2)
        ->assertJsonPath('jobs.0.title', 'Senior Laravel Developer');
});

test('matching returns an empty result set when nothing matches', function () {
    seekerWithCv();
    fakeMatching(['matches_found' => 0, 'jobs' => []]);

    $this->withToken($this->token)
        ->getJson('/api/job-seeker/match-resume-to-jobs')
        ->assertOk()
        ->assertJsonPath('matches_found', 0)
        ->assertJsonPath('jobs', []);
});

test('matching requires a CV on the profile', function () {
    // No profile CV set.
    $this->withToken($this->token)
        ->getJson('/api/job-seeker/match-resume-to-jobs')
        ->assertStatus(422)
        ->assertJsonPath('message', 'No CV found on your profile. Please upload and analyze your CV first.');
});

test('matching returns 422 when the service reports a parse failure', function () {
    seekerWithCv();
    test()->mock(ResumeMatchingService::class)
        ->shouldReceive('matchResumeToJobs')->once()
        ->andThrow(new CvAnalysisException('Invalid resume format', 422));

    $this->withToken($this->token)
        ->getJson('/api/job-seeker/match-resume-to-jobs')
        ->assertStatus(422)
        ->assertJsonPath('message', 'Resume matching failed');
});

test('matching returns 502 when the service is unavailable', function () {
    seekerWithCv();
    test()->mock(ResumeMatchingService::class)
        ->shouldReceive('matchResumeToJobs')->once()
        ->andThrow(new CvAnalysisException('Service unavailable', 502));

    $this->withToken($this->token)
        ->getJson('/api/job-seeker/match-resume-to-jobs')
        ->assertStatus(502)
        ->assertJsonPath('message', 'Resume matching service unavailable');
});

test('an unauthenticated user cannot match a resume', function () {
    $this->getJson('/api/job-seeker/match-resume-to-jobs')->assertUnauthorized();
});

test('an employer cannot use the seeker matching endpoint', function () {
    $this->withToken(tokenFor('employer'))
        ->getJson('/api/job-seeker/match-resume-to-jobs')
        ->assertForbidden();
});
