<?php

// AI job-to-candidate matching endpoints:
//   POST /api/employer/match-candidates
//   POST /api/employer/jobs/{jobPostId}/match-candidates
// The JobMatchingService is mocked so no external AI call is made.

use App\Exceptions\CvAnalysisException;
use App\Services\JobMatchingService;

beforeEach(function () {
    [$this->employer, $this->employerToken] = userWithToken('employer');
});

// ── POST /api/employer/match-candidates ──────────────────────────────

test('employer can match candidates by job description', function () {
    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->with('React developer with 5+ years', 10)
        ->andReturn([
            'extracted_requirements' => ['React', '5+ years'],
            'candidates' => [
                ['resume_id' => 'user123', 'full_name' => 'Jane Smith', 'matched_skills_score' => 85, 'skills' => ['React', 'TypeScript']],
                ['resume_id' => 'user456', 'full_name' => 'John Doe',   'matched_skills_score' => 75, 'skills' => ['React']],
            ],
        ]);

    // NOTE: controller enriches each candidate to keys
    // user_id, resume_id, full_name, matched_skills_score, matched_skills, profile_url
    // (the raw service `skills` key is remapped to `matched_skills`).
    $this->withToken($this->employerToken)->postJson('/api/employer/match-candidates', [
        'job_description' => 'React developer with 5+ years',
        'limit'           => 10,
    ])
        ->assertOk()
        ->assertJsonStructure([
            'extracted_requirements',
            'candidates' => ['*' => ['user_id', 'resume_id', 'full_name', 'matched_skills_score', 'matched_skills', 'profile_url']],
        ])
        ->assertJsonPath('extracted_requirements', ['React', '5+ years'])
        ->assertJsonPath('candidates.0.full_name', 'Jane Smith')
        ->assertJsonPath('candidates.0.matched_skills_score', 85);
});

test('match candidates returns the enriched response structure', function () {
    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->andReturn([
            'extracted_requirements' => ['programming'],
            'candidates' => [
                ['resume_id' => '1', 'full_name' => 'Sarieh Al Tabaa', 'matched_skills_score' => 0, 'skills' => ['React', 'Laravel']],
            ],
        ]);

    $response = $this->withToken($this->employerToken)->postJson('/api/employer/match-candidates', [
        'job_description' => 'programming',
    ])->assertOk();

    expect($response->json())->toHaveKeys(['extracted_requirements', 'candidates'])
        ->and($response->json('candidates.0'))
            ->toHaveKeys(['user_id', 'resume_id', 'full_name', 'matched_skills_score', 'matched_skills', 'profile_url']);
});

test('match candidates defaults the limit to 10', function () {
    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->with('developer', 10)
        ->andReturn(['extracted_requirements' => [], 'candidates' => []]);

    $this->withToken($this->employerToken)->postJson('/api/employer/match-candidates', [
        'job_description' => 'developer',
    ])->assertOk();
});

test('match candidates accepts a custom limit', function () {
    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->with('developer', 5)
        ->andReturn(['extracted_requirements' => [], 'candidates' => []]);

    $this->withToken($this->employerToken)->postJson('/api/employer/match-candidates', [
        'job_description' => 'developer',
        'limit'           => 5,
    ])->assertOk();
});

// ── Validation (manual Validator::make → {errors: {field: []}}) ──────

test('match candidates rejects invalid input', function (array $payload, string $field) {
    $this->withToken($this->employerToken)->postJson('/api/employer/match-candidates', $payload)
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'missing job_description'  => [[], 'job_description'],
    'limit below 1'            => [['job_description' => 'developer', 'limit' => 0], 'limit'],
    'limit above 50'           => [['job_description' => 'developer', 'limit' => 51], 'limit'],
    'job_description too long' => [['job_description' => str_repeat('a', 5001)], 'job_description'],
]);

// ── Service error handling ───────────────────────────────────────────

test('match candidates returns 422 when the service cannot parse the input', function () {
    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->andThrow(new CvAnalysisException('Invalid job description', 422));

    $this->withToken($this->employerToken)->postJson('/api/employer/match-candidates', [
        'job_description' => 'invalid',
    ])->assertStatus(422)
      ->assertJsonPath('message', 'Job matching failed');
});

test('match candidates returns 502 when the service is unavailable', function () {
    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->andThrow(new CvAnalysisException('Service unavailable', 502));

    $this->withToken($this->employerToken)->postJson('/api/employer/match-candidates', [
        'job_description' => 'developer',
    ])->assertStatus(502)
      ->assertJsonPath('message', 'Job matching service unavailable');
});

// ── Guards ───────────────────────────────────────────────────────────

test('a non-employer cannot match candidates', function () {
    $this->withToken(tokenFor('employee'))->postJson('/api/employer/match-candidates', [
        'job_description' => 'developer',
    ])->assertForbidden();
});

test('an unauthenticated user cannot match candidates', function () {
    $this->postJson('/api/employer/match-candidates', [
        'job_description' => 'developer',
    ])->assertUnauthorized();
});

// ── POST /api/employer/jobs/{jobPostId}/match-candidates ─────────────

test('employer can match candidates to their own job post', function () {
    $job = createJob($this->employer, [
        'title'       => 'Senior React Developer',
        'description' => 'Looking for React expert with 5+ years',
    ]);

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->with('Looking for React expert with 5+ years', 10)
        ->andReturn([
            'extracted_requirements' => ['React', '5+ years'],
            'candidates' => [
                ['resume_id' => 'user789', 'full_name' => 'Alice Johnson', 'matched_skills_score' => 90, 'skills' => ['React', 'Redux']],
            ],
        ]);

    $this->withToken($this->employerToken)->postJson("/api/employer/jobs/{$job->_id}/match-candidates")
        ->assertOk()
        ->assertJsonStructure(['job_post' => ['id', 'title'], 'extracted_requirements', 'candidates'])
        ->assertJsonPath('job_post.id', (string) $job->_id)
        ->assertJsonPath('job_post.title', 'Senior React Developer')
        ->assertJsonPath('candidates.0.full_name', 'Alice Johnson');
});

test('match to job post accepts a custom limit', function () {
    $job = createJob($this->employer, ['title' => 'Developer', 'description' => 'Job description']);

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->with('Job description', 20)
        ->andReturn(['extracted_requirements' => [], 'candidates' => []]);

    $this->withToken($this->employerToken)->postJson("/api/employer/jobs/{$job->_id}/match-candidates", [
        'limit' => 20,
    ])->assertOk();
});

test('match to job post falls back to the title when there is no description', function () {
    // createJob defaults a description, so null it out to exercise the fallback.
    $job = createJob($this->employer, ['title' => 'Backend Developer', 'description' => null]);

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->with('Backend Developer', 10)
        ->andReturn(['extracted_requirements' => [], 'candidates' => []]);

    $this->withToken($this->employerToken)->postJson("/api/employer/jobs/{$job->_id}/match-candidates")
        ->assertOk();
});

test('employer cannot match candidates to another employers job post', function () {
    $otherJob = createJob(createUser('employer'), ['title' => 'Developer']);

    $this->withToken($this->employerToken)->postJson("/api/employer/jobs/{$otherJob->_id}/match-candidates")
        ->assertForbidden()
        ->assertJsonPath('message', 'You do not own this job post');
});

test('match to job post returns 404 for a non-existent job', function () {
    $this->withToken($this->employerToken)
        ->postJson('/api/employer/jobs/000000000000000000000000/match-candidates')
        ->assertNotFound()
        ->assertJsonPath('message', 'Job post not found');
});

test('match to job post surfaces service errors', function () {
    $job = createJob($this->employer, ['title' => 'Developer']);

    $mock = $this->mock(JobMatchingService::class);
    $mock->shouldReceive('matchJobToCandidates')
        ->once()
        ->andThrow(new CvAnalysisException('Service unavailable', 502));

    $this->withToken($this->employerToken)->postJson("/api/employer/jobs/{$job->_id}/match-candidates")
        ->assertStatus(502);
});
