<?php

// ============================================================
// Tests for GET /api/job-seeker/matched-jobs
// All users created via factory + auth()->login() to avoid bcrypt.
// ============================================================

use App\Models\Application;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;

// ── Helpers ───────────────────────────────────────────────────

function mjSeeker(array $profileAttrs = []): array
{
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);

    if (!empty($profileAttrs)) {
        JobSeekerProfile::updateOrCreate(
            ['user_id' => (string) $user->_id],
            $profileAttrs
        );
    }

    return [$user, $token];
}

function mjJob(string $employerId, array $overrides = []): JobPost
{
    return JobPost::create(array_merge([
        'title'        => 'Test Job',
        'description'  => 'D',
        'company_name' => 'TestCo',
        'job_type'     => 'full_time',
        'city'         => 'Beirut',
        'vacancies'    => 1,
        'communication_method' => 'by_forsa',
        'employer_id'  => $employerId,
        'is_active'    => true,
    ], $overrides));
}

afterEach(function () {
    JobPost::truncate();
    Application::truncate();
});

beforeEach(function () {
    JobPost::truncate();
    Application::truncate();
});

// ── GET /api/job-seeker/matched-jobs ─────────────────────────

test('returns active jobs with match_score field', function () {
    [$seeker, $token] = mjSeeker();
    $employer = User::factory()->employer()->create();
    mjJob((string) $employer->_id);

    $res = $this->withToken($token)->getJson('/api/job-seeker/matched-jobs')->assertOk();

    expect($res->json('data'))->toHaveCount(1);
    expect($res->json('data.0'))->toHaveKey('match_score');

    $employer->delete(); $seeker->delete();
});

test('jobs with matching skills score higher', function () {
    [$seeker, $token] = mjSeeker(['ai_skills' => ['PHP', 'Laravel', 'JavaScript']]);
    $employer = User::factory()->employer()->create();

    mjJob((string) $employer->_id, ['title' => 'PHP Developer', 'roles' => ['Backend', 'PHP'], 'tags' => ['Laravel']]);
    mjJob((string) $employer->_id, ['title' => 'iOS Developer', 'roles' => ['Mobile', 'iOS'], 'tags' => ['Swift']]);

    $res = $this->withToken($token)->getJson('/api/job-seeker/matched-jobs')->assertOk();

    $jobs = $res->json('data');
    expect($jobs)->toHaveCount(2);
    expect($jobs[0]['title'])->toBe('PHP Developer');
    expect($jobs[0]['match_score'])->toBe(4); // PHP +2, Laravel +2
    expect($jobs[1]['title'])->toBe('iOS Developer');
    expect($jobs[1]['match_score'])->toBe(0);

    $employer->delete(); $seeker->delete();
});

test('location match adds 3 to score', function () {
    [$seeker, $token] = mjSeeker(['ai_location' => 'Beirut, Lebanon', 'ai_skills' => []]);
    $employer = User::factory()->employer()->create();

    mjJob((string) $employer->_id, ['title' => 'Beirut Job', 'city' => 'Beirut']);
    mjJob((string) $employer->_id, ['title' => 'Dubai Job',  'city' => 'Dubai']);

    $res = $this->withToken($token)->getJson('/api/job-seeker/matched-jobs')->assertOk();

    $jobs = $res->json('data');
    $beirut = collect($jobs)->firstWhere('title', 'Beirut Job');
    $dubai  = collect($jobs)->firstWhere('title', 'Dubai Job');

    expect($beirut['match_score'])->toBe(3);
    expect($dubai['match_score'])->toBe(0);

    $employer->delete(); $seeker->delete();
});

test('already-applied jobs are excluded', function () {
    [$seeker, $token] = mjSeeker();
    $employer = User::factory()->employer()->create();

    $job1 = mjJob((string) $employer->_id, ['title' => 'Job 1']);
    $job2 = mjJob((string) $employer->_id, ['title' => 'Job 2']);

    Application::create([
        'user_id'     => (string) $seeker->_id,
        'job_post_id' => (string) $job1->_id,
        'status'      => 'pending',
        'applied_at'  => now(),
    ]);

    $res = $this->withToken($token)->getJson('/api/job-seeker/matched-jobs')->assertOk();

    $titles = collect($res->json('data'))->pluck('title')->toArray();
    expect($titles)->not->toContain('Job 1');
    expect($titles)->toContain('Job 2');

    $employer->delete(); $seeker->delete();
});

test('seeker with no profile gets all active jobs with match_score 0', function () {
    [$seeker, $token] = mjSeeker();
    JobSeekerProfile::where('user_id', (string) $seeker->_id)->delete();

    $employer = User::factory()->employer()->create();
    mjJob((string) $employer->_id, ['title' => 'Active Job']);

    $res = $this->withToken($token)->getJson('/api/job-seeker/matched-jobs')->assertOk();

    expect($res->json('data'))->toHaveCount(1);
    expect($res->json('data.0.match_score'))->toBe(0);

    $employer->delete(); $seeker->delete();
});

test('inactive jobs are not returned', function () {
    [$seeker, $token] = mjSeeker();
    $employer = User::factory()->employer()->create();

    mjJob((string) $employer->_id, ['title' => 'Active',   'is_active' => true]);
    mjJob((string) $employer->_id, ['title' => 'Inactive', 'is_active' => false]);

    $res = $this->withToken($token)->getJson('/api/job-seeker/matched-jobs')->assertOk();

    $titles = collect($res->json('data'))->pluck('title')->toArray();
    expect($titles)->toContain('Active');
    expect($titles)->not->toContain('Inactive');

    $employer->delete(); $seeker->delete();
});

test('returns correct pagination shape', function () {
    [$seeker, $token] = mjSeeker();

    $res = $this->withToken($token)->getJson('/api/job-seeker/matched-jobs')->assertOk();

    expect($res->json())->toHaveKeys(['data', 'current_page', 'per_page', 'total', 'total_pages', 'next_page', 'prev_page']);

    $seeker->delete();
});

test('unauthenticated user cannot access matched jobs', function () {
    $this->getJson('/api/job-seeker/matched-jobs')->assertUnauthorized();
});

test('employer cannot access job seeker matched jobs', function () {
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);

    $this->withToken($token)->getJson('/api/job-seeker/matched-jobs')->assertForbidden();

    $employer->delete();
});

test('min_score filter works', function () {
    [$seeker, $token] = mjSeeker(['ai_skills' => ['PHP', 'Laravel']]);
    $employer = User::factory()->employer()->create();

    mjJob((string) $employer->_id, ['title' => 'High Match', 'roles' => ['PHP'], 'tags' => ['Laravel']]);
    mjJob((string) $employer->_id, ['title' => 'No Match']);

    $res = $this->withToken($token)->getJson('/api/job-seeker/matched-jobs?min_score=3')->assertOk();

    $titles = collect($res->json('data'))->pluck('title')->toArray();
    expect($titles)->toContain('High Match');
    expect($titles)->not->toContain('No Match');

    $employer->delete(); $seeker->delete();
});
