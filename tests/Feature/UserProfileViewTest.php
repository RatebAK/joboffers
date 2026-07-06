<?php

// ============================================================
// Tests for GET /api/users/{id} and admin user listing endpoints.
// All users created via factory + auth()->login() to avoid bcrypt.
// ============================================================

use App\Models\CompanyProfile;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;

// ── Helpers ───────────────────────────────────────────────────

function uvSeeker(array $attrs = []): array
{
    $user  = User::factory()->employee()->create($attrs);
    $token = auth('api')->login($user);
    return [$user, $token];
}

function uvEmployer(array $attrs = []): array
{
    $user  = User::factory()->employer()->create($attrs);
    $token = auth('api')->login($user);
    return [$user, $token];
}

function uvAdmin(): array
{
    $user  = User::factory()->admin()->create();
    $token = auth('api')->login($user);
    return [$user, $token];
}

// ── GET /api/users/{id} ───────────────────────────────────────

test('authenticated user can view a job seeker profile', function () {
    [$seeker]         = uvSeeker();
    [, $viewerToken]  = uvSeeker();

    // Ensure profile exists then set AI fields
    $profile = JobSeekerProfile::firstOrCreate(['user_id' => (string) $seeker->_id]);
    $profile->ai_skills = ['PHP', 'Laravel'];
    $profile->ats_score = 80;
    $profile->save();

    $res = $this->withToken($viewerToken)
        ->getJson("/api/users/{$seeker->_id}")
        ->assertOk();

    expect($res->json('user.name'))->toBe($seeker->name);
    expect($res->json('user.profile.ai_skills'))->toBe(['PHP', 'Laravel']);
    expect($res->json('user.profile.ats_score'))->toBe(80);

    $seeker->delete();
});

test('authenticated user can view an employer profile with company data', function () {
    [$employer] = uvEmployer();
    [, $token]  = uvSeeker();

    CompanyProfile::create([
        'employer_id' => (string) $employer->_id,
        'name'        => 'Acme Corp',
        'slug'        => 'acme-corp-' . uniqid(),
        'industry'    => 'Tech',
    ]);

    $res = $this->withToken($token)
        ->getJson("/api/users/{$employer->_id}")
        ->assertOk();

    expect($res->json('user.name'))->toBe($employer->name);
    expect($res->json('user.profile.name'))->toBe('Acme Corp');

    CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('employer profile includes open_positions_count', function () {
    [$employer] = uvEmployer();
    [, $token]  = uvSeeker();

    CompanyProfile::create([
        'employer_id' => (string) $employer->_id,
        'name'        => 'JobsCo',
        'slug'        => 'jobsco-' . uniqid(),
    ]);
    JobPost::create([
        'employer_id'  => (string) $employer->_id,
        'title'        => 'Dev',
        'description'  => 'D',
        'company_name' => 'JobsCo',
        'job_type'     => 'full_time',
        'city'         => 'Beirut',
        'vacancies'    => 1,
        'communication_method' => 'by_forsa',
        'is_active'    => true,
    ]);

    $res = $this->withToken($token)
        ->getJson("/api/users/{$employer->_id}")
        ->assertOk();

    expect($res->json('user.profile.open_positions_count'))->toBe(1);

    JobPost::where('employer_id', (string) $employer->_id)->delete();
    CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('employer without company profile returns null profile', function () {
    [$employer] = uvEmployer();
    [, $token]  = uvAdmin();

    $res = $this->withToken($token)
        ->getJson("/api/users/{$employer->_id}")
        ->assertOk();

    expect($res->json('user.profile'))->toBeNull();
    $employer->delete();
});

test('job seeker without profile returns null profile', function () {
    [$seeker] = uvSeeker();
    [, $token] = uvAdmin();

    JobSeekerProfile::where('user_id', (string) $seeker->_id)->delete();

    $res = $this->withToken($token)
        ->getJson("/api/users/{$seeker->_id}")
        ->assertOk();

    expect($res->json('user.profile'))->toBeNull();
    $seeker->delete();
});

test('returns 404 for non-existent user', function () {
    [, $token] = uvAdmin();

    $this->withToken($token)
        ->getJson('/api/users/000000000000000000000000')
        ->assertNotFound()
        ->assertJsonPath('message', 'User not found');
});

test('profile response does not expose password', function () {
    [$seeker, $token] = uvSeeker();

    $res = $this->withToken($token)
        ->getJson("/api/users/{$seeker->_id}")
        ->assertOk();

    expect($res->json('user'))->not->toHaveKey('password');
    $seeker->delete();
});

test('user id in response is a 24-char MongoDB ObjectId string', function () {
    [$seeker, $token] = uvSeeker();

    $res = $this->withToken($token)
        ->getJson("/api/users/{$seeker->_id}")
        ->assertOk();

    expect($res->json('user.id'))->toBeString();
    expect(strlen($res->json('user.id')))->toBe(24);
    $seeker->delete();
});

test('unauthenticated user cannot view profiles', function () {
    $this->getJson('/api/users/000000000000000000000000')->assertUnauthorized();
});

test('user with multiple roles is returned correctly', function () {
    $user  = User::factory()->withRoles(['employee', 'employer'])->create();
    [, $token] = uvAdmin();

    $res = $this->withToken($token)
        ->getJson("/api/users/{$user->_id}")
        ->assertOk();

    expect($res->json('user.roles'))->toContain('employee');
    expect($res->json('user.roles'))->toContain('employer');
    $user->delete();
});

// ── GET /api/admin/users ──────────────────────────────────────

test('admin can list all users with pagination', function () {
    [$admin, $adminToken] = uvAdmin();
    $users = User::factory()->employee()->count(3)->create();

    $res = $this->withToken($adminToken)
        ->getJson('/api/admin/users?per_page=2')
        ->assertOk();

    expect($res->json('per_page'))->toBe(2);
    expect($res->json('total'))->toBeGreaterThanOrEqual(4); // 3 + admin

    $users->each->delete();
    $admin->delete();
});

test('admin list all users per_page capped at 100', function () {
    [$admin, $adminToken] = uvAdmin();

    $res = $this->withToken($adminToken)
        ->getJson('/api/admin/users?per_page=200')
        ->assertOk();

    expect($res->json('per_page'))->toBe(100);
    $admin->delete();
});

test('non-admin cannot access admin user list', function () {
    [, $token] = uvSeeker();

    $this->withToken($token)->getJson('/api/admin/users')->assertForbidden();
    $this->withToken($token)->getJson('/api/admin/users/seekers')->assertForbidden();
    $this->withToken($token)->getJson('/api/admin/users/employers')->assertForbidden();
});

// ── GET /api/admin/users/seekers ─────────────────────────────

test('admin can list job seekers with their profiles', function () {
    [$admin, $adminToken] = uvAdmin();
    [$s1] = uvSeeker();
    [$s2] = uvSeeker();

    $p1 = JobSeekerProfile::firstOrCreate(['user_id' => (string) $s1->_id]);
    $p1->ai_skills = ['PHP'];
    $p1->save();

    $p2 = JobSeekerProfile::firstOrCreate(['user_id' => (string) $s2->_id]);
    $p2->ai_skills = ['JS'];
    $p2->save();

    $res = $this->withToken($adminToken)
        ->getJson('/api/admin/users/seekers')
        ->assertOk()
        ->assertJsonStructure(['data', 'total', 'per_page', 'current_page', 'total_pages', 'next_page', 'prev_page']);

    $skills = collect($res->json('data'))->pluck('profile.ai_skills')->flatten()->toArray();
    expect($skills)->toContain('PHP');
    expect($skills)->toContain('JS');

    $s1->delete(); $s2->delete(); $admin->delete();
});

test('admin seekers list pagination works', function () {
    [$admin, $adminToken] = uvAdmin();
    $seekers = User::factory()->employee()->count(5)->create();
    foreach ($seekers as $s) {
        JobSeekerProfile::firstOrCreate(['user_id' => (string) $s->_id]);
    }

    $page1 = $this->withToken($adminToken)
        ->getJson('/api/admin/users/seekers?per_page=3&page=1')
        ->assertOk();

    expect($page1->json('per_page'))->toBe(3);
    expect($page1->json('current_page'))->toBe(1);
    expect($page1->json('next_page'))->toBeGreaterThan(0);
    expect($page1->json('prev_page'))->toBeNull();

    $seekers->each->delete();
    $admin->delete();
});

// ── GET /api/admin/users/employers ───────────────────────────

test('admin can list employers with company profiles and open_positions_count', function () {
    [$admin, $adminToken] = uvAdmin();
    [$emp1] = uvEmployer();
    [$emp2] = uvEmployer();

    CompanyProfile::create(['employer_id' => (string) $emp1->_id, 'name' => 'Corp A', 'slug' => 'corp-a-' . uniqid()]);
    JobPost::create([
        'employer_id' => (string) $emp1->_id, 'title' => 'Job', 'description' => 'D',
        'company_name' => 'Corp A', 'job_type' => 'full_time', 'city' => 'Beirut',
        'vacancies' => 1, 'communication_method' => 'by_forsa', 'is_active' => true,
    ]);
    CompanyProfile::create(['employer_id' => (string) $emp2->_id, 'name' => 'Corp B', 'slug' => 'corp-b-' . uniqid()]);

    $res = $this->withToken($adminToken)
        ->getJson('/api/admin/users/employers')
        ->assertOk();

    // Find the two employers we created in the response
    $data = collect($res->json('data'));
    $empA = $data->first(fn($e) => ($e['profile']['name'] ?? '') === 'Corp A');
    $empB = $data->first(fn($e) => ($e['profile']['name'] ?? '') === 'Corp B');

    expect($empA)->not->toBeNull();
    expect($empA['profile']['open_positions_count'])->toBe(1);
    expect($empB)->not->toBeNull();
    expect($empB['profile']['open_positions_count'])->toBe(0);

    JobPost::where('employer_id', (string) $emp1->_id)->delete();
    CompanyProfile::where('employer_id', (string) $emp1->_id)->delete();
    CompanyProfile::where('employer_id', (string) $emp2->_id)->delete();
    $emp1->delete(); $emp2->delete(); $admin->delete();
});
