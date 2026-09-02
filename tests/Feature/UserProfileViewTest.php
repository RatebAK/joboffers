<?php

// =============================================================================
// UserProfileViewTest
//   GET /api/users/{id}              (public profile view)
//   GET /api/admin/users[/seekers|/employers]  (admin listings)
// =============================================================================

use App\Models\JobSeekerProfile;
use App\Models\User;

// ── GET /api/users/{id} ──────────────────────────────────────────────────

test('an authenticated user can view a job seeker profile', function () {
    [$seeker] = createSeekerWithProfile([], ['ai_skills' => ['PHP', 'Laravel'], 'ats_score' => 80]);

    $this->withToken(tokenFor('employee'))
        ->getJson("/api/users/{$seeker->_id}")
        ->assertOk()
        ->assertJsonPath('user.name', $seeker->name)
        ->assertJsonPath('user.profile.ai_skills', ['PHP', 'Laravel'])
        ->assertJsonPath('user.profile.ats_score', 80);
});

test('an authenticated user can view an employer profile with company data', function () {
    $employer = createUser('employer');
    createCompanyFor($employer, ['name' => 'Acme Corp', 'industry' => 'Tech']);

    $this->withToken(tokenFor('employee'))
        ->getJson("/api/users/{$employer->_id}")
        ->assertOk()
        ->assertJsonPath('user.name', $employer->name)
        ->assertJsonPath('user.profile.name', 'Acme Corp');
});

test('an employer profile includes an open_positions_count', function () {
    $employer = createUser('employer');
    createCompanyFor($employer, ['name' => 'JobsCo']);
    createJob($employer, ['company_name' => 'JobsCo']);

    $this->withToken(tokenFor('employee'))
        ->getJson("/api/users/{$employer->_id}")
        ->assertOk()
        ->assertJsonPath('user.profile.open_positions_count', 1);
});

test('an employer without a company profile returns a null profile', function () {
    $employer = createUser('employer');

    $this->withToken(tokenFor('admin'))
        ->getJson("/api/users/{$employer->_id}")
        ->assertOk()
        ->assertJsonPath('user.profile', null);
});

test('a job seeker without a profile returns a null profile', function () {
    $seeker = createUser('employee');

    $this->withToken(tokenFor('admin'))
        ->getJson("/api/users/{$seeker->_id}")
        ->assertOk()
        ->assertJsonPath('user.profile', null);
});

test('viewing a non-existent user returns 404', function () {
    $this->withToken(tokenFor('admin'))
        ->getJson('/api/users/000000000000000000000000')
        ->assertNotFound()
        ->assertJsonPath('message', 'User not found');
});

test('the profile view never exposes the password', function () {
    [$seeker] = createSeekerWithProfile();

    expect($this->withToken(tokenFor('employee'))->getJson("/api/users/{$seeker->_id}")->assertOk()->json('user'))
        ->not->toHaveKey('password');
});

test('the returned user id is a 24-character ObjectId string', function () {
    [$seeker] = createSeekerWithProfile();

    $id = $this->withToken(tokenFor('employee'))->getJson("/api/users/{$seeker->_id}")->assertOk()->json('user.id');

    expect($id)->toBeString()->toHaveLength(24);
});

test('the profile view is public — an unknown id returns 404 even when unauthenticated', function () {
    // GET /api/users/{id} is a public route, so no auth is required.
    $this->getJson('/api/users/000000000000000000000000')->assertNotFound();
});

test('a multi-role user is returned with all their roles', function () {
    $user = createUser('employee', ['roles' => ['employee', 'employer']]);

    $roles = $this->withToken(tokenFor('admin'))->getJson("/api/users/{$user->_id}")->assertOk()->json('user.roles');

    expect($roles)->toContain('employee')->toContain('employer');
});

// ── GET /api/admin/users ─────────────────────────────────────────────────

test('an admin can list all users with pagination', function () {
    User::factory()->employee()->count(3)->create();

    $response = $this->withToken(tokenFor('admin'))->getJson('/api/admin/users?per_page=2')->assertOk();

    expect($response->json('per_page'))->toBe(2)
        ->and($response->json('total'))->toBeGreaterThanOrEqual(4); // 3 seekers + the admin
});

test('the admin user list caps per_page at 100', function () {
    expect($this->withToken(tokenFor('admin'))->getJson('/api/admin/users?per_page=200')->assertOk()->json('per_page'))
        ->toBe(100);
});

test('a non-admin cannot access any admin user listing', function () {
    $token = tokenFor('employee');

    $this->withToken($token)->getJson('/api/admin/users')->assertForbidden();
    $this->withToken($token)->getJson('/api/admin/users/seekers')->assertForbidden();
    $this->withToken($token)->getJson('/api/admin/users/employers')->assertForbidden();
});

// ── GET /api/admin/users/seekers ─────────────────────────────────────────

test('an admin can list job seekers with their profiles', function () {
    createSeekerWithProfile([], ['ai_skills' => ['PHP']]);
    createSeekerWithProfile([], ['ai_skills' => ['JS']]);

    $response = $this->withToken(tokenFor('admin'))
        ->getJson('/api/admin/users/seekers')
        ->assertOk()
        ->assertJsonStructure(['data', 'total', 'per_page', 'current_page', 'total_pages', 'next_page', 'prev_page']);

    $skills = collect($response->json('data'))->pluck('profile.ai_skills')->flatten();
    expect($skills)->toContain('PHP')->toContain('JS');
});

test('the admin seekers listing paginates', function () {
    $seekers = User::factory()->employee()->count(5)->create();
    $seekers->each(fn ($s) => JobSeekerProfile::create(['user_id' => (string) $s->_id]));

    $page1 = $this->withToken(tokenFor('admin'))->getJson('/api/admin/users/seekers?per_page=3&page=1')->assertOk();

    expect($page1->json('per_page'))->toBe(3)
        ->and($page1->json('current_page'))->toBe(1)
        ->and($page1->json('prev_page'))->toBeNull();
});

// ── GET /api/admin/users/employers ───────────────────────────────────────

test('an admin can list employers with company profiles and open_positions_count', function () {
    $withJob = createUser('employer');
    createCompanyFor($withJob, ['name' => 'Corp A']);
    createJob($withJob, ['company_name' => 'Corp A']);

    $withoutJob = createUser('employer');
    createCompanyFor($withoutJob, ['name' => 'Corp B']);

    $data = collect($this->withToken(tokenFor('admin'))->getJson('/api/admin/users/employers')->assertOk()->json('data'));

    expect($data->firstWhere('profile.name', 'Corp A')['profile']['open_positions_count'])->toBe(1)
        ->and($data->firstWhere('profile.name', 'Corp B')['profile']['open_positions_count'])->toBe(0);
});
