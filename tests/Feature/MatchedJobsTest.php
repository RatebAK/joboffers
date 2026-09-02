<?php

// =============================================================================
// MatchedJobsTest — GET /api/job-seeker/matched-jobs
//
// Returns active jobs scored against the seeker's profile (skills + location),
// excluding jobs already applied to.
// =============================================================================

use App\Models\Application;
use App\Models\JobSeekerProfile;
use App\Models\User;

beforeEach(function () {
    [$this->seeker, $this->token] = userWithToken('employee');
    $this->employer = createUser('employer');
});

/** Give the current seeker a profile with the given attributes. */
function matchProfile(array $attributes): void
{
    JobSeekerProfile::updateOrCreate(['user_id' => (string) test()->seeker->_id], $attributes);
}

test('matched jobs include a match_score', function () {
    createJob($this->employer);

    $this->withToken($this->token)->getJson('/api/job-seeker/matched-jobs')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonStructure(['data' => [['match_score']]]);
});

test('jobs matching the seekers skills score higher', function () {
    matchProfile(['ai_skills' => ['PHP', 'Laravel', 'JavaScript']]);
    createJob($this->employer, ['title' => 'PHP Developer', 'roles' => ['Backend', 'PHP'], 'tags' => ['Laravel']]);
    createJob($this->employer, ['title' => 'iOS Developer', 'roles' => ['Mobile', 'iOS'], 'tags' => ['Swift']]);

    $jobs = $this->withToken($this->token)->getJson('/api/job-seeker/matched-jobs')->assertOk()->json('data');

    expect($jobs[0]['title'])->toBe('PHP Developer')
        ->and($jobs[0]['match_score'])->toBe(4) // PHP +2, Laravel +2
        ->and($jobs[1]['match_score'])->toBe(0);
});

test('a location match adds to the score', function () {
    matchProfile(['ai_location' => 'Beirut, Lebanon', 'ai_skills' => []]);
    createJob($this->employer, ['title' => 'Beirut Job', 'city' => 'Beirut']);
    createJob($this->employer, ['title' => 'Dubai Job', 'city' => 'Dubai']);

    $jobs = collect($this->withToken($this->token)->getJson('/api/job-seeker/matched-jobs')->assertOk()->json('data'));

    expect($jobs->firstWhere('title', 'Beirut Job')['match_score'])->toBe(3)
        ->and($jobs->firstWhere('title', 'Dubai Job')['match_score'])->toBe(0);
});

test('jobs already applied to are excluded', function () {
    $applied = createJob($this->employer, ['title' => 'Job 1']);
    createJob($this->employer, ['title' => 'Job 2']);
    Application::create(['user_id' => (string) $this->seeker->_id, 'job_post_id' => (string) $applied->_id, 'status' => 'pending', 'applied_at' => now()]);

    $titles = collect($this->withToken($this->token)->getJson('/api/job-seeker/matched-jobs')->assertOk()->json('data'))->pluck('title');

    expect($titles)->not->toContain('Job 1')->toContain('Job 2');
});

test('a seeker with no profile gets all active jobs with match_score 0', function () {
    JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->delete();
    createJob($this->employer, ['title' => 'Active Job']);

    $this->withToken($this->token)->getJson('/api/job-seeker/matched-jobs')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.match_score', 0);
});

test('inactive jobs are not returned', function () {
    createJob($this->employer, ['title' => 'Active', 'is_active' => true]);
    createJob($this->employer, ['title' => 'Inactive', 'is_active' => false]);

    $titles = collect($this->withToken($this->token)->getJson('/api/job-seeker/matched-jobs')->assertOk()->json('data'))->pluck('title');

    expect($titles)->toContain('Active')->not->toContain('Inactive');
});

test('the min_score filter excludes low-scoring jobs', function () {
    matchProfile(['ai_skills' => ['PHP', 'Laravel']]);
    createJob($this->employer, ['title' => 'High Match', 'roles' => ['PHP'], 'tags' => ['Laravel']]);
    createJob($this->employer, ['title' => 'No Match']);

    $titles = collect($this->withToken($this->token)->getJson('/api/job-seeker/matched-jobs?min_score=3')->assertOk()->json('data'))->pluck('title');

    expect($titles)->toContain('High Match')->not->toContain('No Match');
});

test('the response has the standard pagination shape', function () {
    $this->withToken($this->token)->getJson('/api/job-seeker/matched-jobs')
        ->assertOk()
        ->assertJsonStructure(['data', 'current_page', 'per_page', 'total', 'total_pages', 'next_page', 'prev_page']);
});

test('an unauthenticated user cannot access matched jobs', function () {
    $this->getJson('/api/job-seeker/matched-jobs')->assertUnauthorized();
});

test('an employer cannot access seeker matched jobs', function () {
    $this->withToken(tokenFor('employer'))->getJson('/api/job-seeker/matched-jobs')->assertForbidden();
});
