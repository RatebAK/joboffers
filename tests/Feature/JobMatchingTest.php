<?php

// ============================================================
// Tests for AI-powered job-to-candidate matching endpoints.
// Covers: matching by job description, matching by job post,
// response structure, error handling, auth guards, and ownership.
// ============================================================

use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;
use App\Services\JobMatchingService;
use App\Exceptions\CvAnalysisException;

// ── Helpers ───────────────────────────────────────────────────

function matchingEmployer(): array
{
    $employer = User::factory()->employer()->create();
    $token = auth('api')->login($employer);

    return [$employer, $token];
}

function matchingSeeker(): array
{
    $seeker = User::factory()->employee()->create();
    $token = auth('api')->login($seeker);

    return [$seeker, $token];
}

// ── POST /api/employer/match-candidates ───────────────────────

test('employer can match candidates by job description', function () {
    [$employer, $token] = matchingEmployer();

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->with('React developer with 5+ years', 10)
        ->andReturn([
            'extracted_requirements' => ['React', '5+ years'],
            'candidates' => [
                [
                    'resume_id' => 'user123',
                    'full_name' => 'Jane Smith',
                    'matched_skills_score' => 85,
                    'skills' => ['React', 'TypeScript', 'Node.js'],
                ],
                [
                    'resume_id' => 'user456',
                    'full_name' => 'John Doe',
                    'matched_skills_score' => 75,
                    'skills' => ['React', 'JavaScript'],
                ],
            ],
        ]);

    $response = $this->withToken($token)->postJson('/api/employer/match-candidates', [
        'job_description' => 'React developer with 5+ years',
        'limit' => 10,
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure([
            'extracted_requirements',
            'candidates' => [
                '*' => ['user_id', 'resume_id', 'full_name', 'matched_skills_score', 'skills', 'profile_url'],
            ],
        ])
        ->assertJsonPath('extracted_requirements', ['React', '5+ years'])
        ->assertJsonPath('candidates.0.full_name', 'Jane Smith')
        ->assertJsonPath('candidates.0.matched_skills_score', 85);

    $employer->delete();
});

test('match candidates returns correct response structure', function () {
    [$employer, $token] = matchingEmployer();

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->andReturn([
            'extracted_requirements' => ['programming'],
            'candidates' => [
                [
                    'resume_id' => '1',
                    'full_name' => 'Sarieh Al Tabaa',
                    'matched_skills_score' => 0,
                    'skills' => ['React', 'Laravel', 'Node.js'],
                ],
            ],
        ]);

    $response = $this->withToken($token)->postJson('/api/employer/match-candidates', [
        'job_description' => 'programming',
    ]);

    $response->assertStatus(200);
    expect($response->json())->toHaveKeys(['extracted_requirements', 'candidates']);
    expect($response->json('candidates.0'))->toHaveKeys(['user_id', 'resume_id', 'full_name', 'matched_skills_score', 'skills', 'profile_url']);

    $employer->delete();
});

test('match candidates uses default limit of 10', function () {
    [$employer, $token] = matchingEmployer();

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->with('developer', 10)
        ->andReturn(['extracted_requirements' => [], 'candidates' => []]);

    $this->withToken($token)->postJson('/api/employer/match-candidates', [
        'job_description' => 'developer',
    ])->assertStatus(200);

    $employer->delete();
});

test('match candidates accepts custom limit', function () {
    [$employer, $token] = matchingEmployer();

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->with('developer', 5)
        ->andReturn(['extracted_requirements' => [], 'candidates' => []]);

    $this->withToken($token)->postJson('/api/employer/match-candidates', [
        'job_description' => 'developer',
        'limit' => 5,
    ])->assertStatus(200);

    $employer->delete();
});

test('match candidates requires job_description', function () {
    [, $token] = matchingEmployer();

    $this->withToken($token)->postJson('/api/employer/match-candidates', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['job_description']]);
});

test('match candidates rejects limit below 1', function () {
    [, $token] = matchingEmployer();

    $this->withToken($token)->postJson('/api/employer/match-candidates', [
        'job_description' => 'developer',
        'limit' => 0,
    ])->assertStatus(422)
      ->assertJsonStructure(['errors' => ['limit']]);
});

test('match candidates rejects limit above 50', function () {
    [, $token] = matchingEmployer();

    $this->withToken($token)->postJson('/api/employer/match-candidates', [
        'job_description' => 'developer',
        'limit' => 51,
    ])->assertStatus(422)
      ->assertJsonStructure(['errors' => ['limit']]);
});

test('match candidates rejects job_description over 5000 chars', function () {
    [, $token] = matchingEmployer();

    $this->withToken($token)->postJson('/api/employer/match-candidates', [
        'job_description' => str_repeat('a', 5001),
    ])->assertStatus(422)
      ->assertJsonStructure(['errors' => ['job_description']]);
});

test('match candidates returns 422 when service fails to parse', function () {
    [$employer, $token] = matchingEmployer();

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->andThrow(new CvAnalysisException('Invalid job description', 422));

    $this->withToken($token)->postJson('/api/employer/match-candidates', [
        'job_description' => 'invalid',
    ])->assertStatus(422)
      ->assertJsonPath('message', 'Job matching failed');

    $employer->delete();
});

test('match candidates returns 502 when service unavailable', function () {
    [$employer, $token] = matchingEmployer();

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->andThrow(new CvAnalysisException('Service unavailable', 502));

    $this->withToken($token)->postJson('/api/employer/match-candidates', [
        'job_description' => 'developer',
    ])->assertStatus(502)
      ->assertJsonPath('message', 'Job matching service unavailable');

    $employer->delete();
});

test('non-employer cannot match candidates', function () {
    [$seeker, $token] = matchingSeeker();

    $this->withToken($token)->postJson('/api/employer/match-candidates', [
        'job_description' => 'developer',
    ])->assertStatus(403);

    $seeker->delete();
});

test('unauthenticated user cannot match candidates', function () {
    $this->postJson('/api/employer/match-candidates', [
        'job_description' => 'developer',
    ])->assertStatus(401);
});

// ── POST /api/employer/jobs/{jobPostId}/match-candidates ──────

test('employer can match candidates to their own job post', function () {
    [$employer, $token] = matchingEmployer();

    $jobPost = JobPost::create([
        'employer_id' => $employer->_id,
        'title' => 'Senior React Developer',
        'description' => 'Looking for React expert with 5+ years',
        'location' => 'Remote',
        'job_type' => 'full_time',
        'is_active' => true,
    ]);

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->with('Looking for React expert with 5+ years', 10)
        ->andReturn([
            'extracted_requirements' => ['React', '5+ years'],
            'candidates' => [
                [
                    'resume_id' => 'user789',
                    'full_name' => 'Alice Johnson',
                    'matched_skills_score' => 90,
                    'skills' => ['React', 'Redux', 'TypeScript'],
                ],
            ],
        ]);

    $response = $this->withToken($token)->postJson("/api/employer/jobs/{$jobPost->_id}/match-candidates");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'job_post' => ['id', 'title'],
            'extracted_requirements',
            'candidates',
        ])
        ->assertJsonPath('job_post.id', (string) $jobPost->_id)
        ->assertJsonPath('job_post.title', 'Senior React Developer')
        ->assertJsonPath('candidates.0.full_name', 'Alice Johnson');

    $jobPost->delete();
    $employer->delete();
});

test('match to job post uses custom limit', function () {
    [$employer, $token] = matchingEmployer();

    $jobPost = JobPost::create([
        'employer_id' => $employer->_id,
        'title' => 'Developer',
        'description' => 'Job description',
        'location' => 'Remote',
        'is_active' => true,
    ]);

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->with('Job description', 20)
        ->andReturn(['extracted_requirements' => [], 'candidates' => []]);

    $this->withToken($token)->postJson("/api/employer/jobs/{$jobPost->_id}/match-candidates", [
        'limit' => 20,
    ])->assertStatus(200);

    $jobPost->delete();
    $employer->delete();
});

test('match to job post falls back to title if no description', function () {
    [$employer, $token] = matchingEmployer();

    $jobPost = JobPost::create([
        'employer_id' => $employer->_id,
        'title' => 'Backend Developer',
        'location' => 'Remote',
        'is_active' => true,
    ]);

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->with('Backend Developer', 10)
        ->andReturn(['extracted_requirements' => [], 'candidates' => []]);

    $this->withToken($token)->postJson("/api/employer/jobs/{$jobPost->_id}/match-candidates")
        ->assertStatus(200);

    $jobPost->delete();
    $employer->delete();
});

test('employer cannot match candidates to another employers job post', function () {
    [$employer1, $token1] = matchingEmployer();
    [$employer2,] = matchingEmployer();

    $jobPost = JobPost::create([
        'employer_id' => $employer2->_id,
        'title' => 'Developer',
        'location' => 'Remote',
        'is_active' => true,
    ]);

    $this->withToken($token1)->postJson("/api/employer/jobs/{$jobPost->_id}/match-candidates")
        ->assertStatus(403)
        ->assertJsonPath('message', 'You do not own this job post');

    $jobPost->delete();
    $employer1->delete();
    $employer2->delete();
});

test('match to job post returns 404 for non-existent job', function () {
    [, $token] = matchingEmployer();

    $this->withToken($token)->postJson('/api/employer/jobs/000000000000000000000000/match-candidates')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Job post not found');
});

test('match to job post handles service errors', function () {
    [$employer, $token] = matchingEmployer();

    $jobPost = JobPost::create([
        'employer_id' => $employer->_id,
        'title' => 'Developer',
        'location' => 'Remote',
        'is_active' => true,
    ]);

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->andThrow(new CvAnalysisException('Service unavailable', 502));

    $this->withToken($token)->postJson("/api/employer/jobs/{$jobPost->_id}/match-candidates")
        ->assertStatus(502);

    $jobPost->delete();
    $employer->delete();
});
