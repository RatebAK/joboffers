<?php

// =============================================================================
// JobSeekerProfileTest
//
// Job seeker profile: fetching, the full-payload PUT, per-section updates
// (personal / career / social / skills / education / work experience),
// their validation rules, and access control.
// =============================================================================

use App\Models\JobSeekerProfile;

beforeEach(function () {
    [$this->seeker, $this->token] = userWithToken('employee');
});

/** The current seeker's stored profile. */
function seekerProfile(): ?JobSeekerProfile
{
    return JobSeekerProfile::where('user_id', (string) test()->seeker->_id)->first();
}

/** A complete profile payload for the legacy full PUT endpoint. */
function fullProfilePayload(array $overrides = []): array
{
    return array_merge([
        'first_name'          => 'John',
        'last_name'           => 'Doe',
        'full_name'           => 'John Doe',
        'gender'              => 'male',
        'nationality'         => 'American',
        'city'                => 'New York',
        'phone'               => '+1 (555) 123-4567',
        'date_of_birth'       => '1990-05-15',
        'marital_status'      => 'single',
        'salary_range_from'   => 80000,
        'salary_range_to'     => 120000,
        'current_job_status'  => 'employed',
        'years_of_experience' => 5,
        'education_level'     => 'bachelor',
        'job_level'           => 'mid',
        'job_types'           => ['full-time', 'remote'],
        'job_roles'           => ['frontend', 'fullstack'],
        'work_cities'         => ['new-york', 'remote'],
        'is_actively_seeking' => true,
        'social_links'        => [
            'linkedin' => 'https://linkedin.com/in/johndoe',
            'github'   => 'https://github.com/johndoe',
        ],
        'skills' => [
            ['name' => 'React', 'level' => 'expert'],
            ['name' => 'Node.js', 'level' => 'advanced'],
        ],
        'education_history' => [
            ['certificate_type' => 'bachelor', 'university' => 'harvard', 'major_name' => 'Computer Science', 'from_date' => '2015-09', 'awarded_date' => '2019-06'],
        ],
        'work_experience' => [
            ['job_title' => 'engineer', 'company_name' => 'Tech Corp', 'from_date' => '2020-01', 'is_currently_working' => true],
        ],
    ], $overrides);
}

// ── Fetch ────────────────────────────────────────────────────────────────

test('a job seeker can fetch their profile (auto-created if missing)', function () {
    $this->withToken($this->token)->getJson('/api/job-seeker/profile')
        ->assertOk()
        ->assertJsonStructure(['profile']);
});

test('an unauthenticated user cannot fetch a job seeker profile', function () {
    $this->getJson('/api/job-seeker/profile')->assertUnauthorized();
});

// ── Full-payload PUT ───────────────────────────────────────────────────────

test('the full PUT stores every profile field', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile', fullProfilePayload())
        ->assertOk()
        ->assertJsonPath('profile.first_name', 'John')
        ->assertJsonPath('profile.city', 'New York')
        ->assertJsonPath('profile.years_of_experience', 5)
        ->assertJsonPath('profile.job_level', 'mid');

    $profile = seekerProfile();
    expect($profile->job_types)->toBe(['full-time', 'remote'])
        ->and($profile->social_links['linkedin'])->toBe('https://linkedin.com/in/johndoe')
        ->and($profile->skills)->toHaveCount(2)
        ->and($profile->education_history[0]['major_name'])->toBe('Computer Science')
        ->and($profile->work_experience[0]['company_name'])->toBe('Tech Corp');
});

test('the full PUT validates enum fields', function (string $field, string $value) {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile', [$field => $value])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'gender'    => ['gender', 'robot'],
    'job_level' => ['job_level', 'god'],
]);

test('the full PUT rejects an invalid skill level', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile', [
        'skills' => [['name' => 'PHP', 'level' => 'godlike']],
    ])->assertStatus(422);
});

// ── Section: personal info ─────────────────────────────────────────────────

test('a seeker can update personal information', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'first_name' => 'Jane', 'last_name' => 'Doe', 'full_name' => 'Jane Doe',
        'gender' => 'female', 'city' => 'Beirut', 'phone' => '+961 70 123456',
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Personal information updated successfully')
        ->assertJsonPath('profile.full_name', 'Jane Doe');

    expect(seekerProfile()->phone)->toBe('+961 70 123456');
});

test('personal info validates phone length and gender', function (string $field, mixed $value) {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [$field => $value])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'phone'  => ['phone', str_repeat('1', 21)],
    'gender' => ['gender', 'invalid_gender'],
]);

// ── Section: career info ───────────────────────────────────────────────────

test('a seeker can update career information', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/career-info', [
        'current_job_title' => 'Senior Frontend Developer',
        'years_of_experience' => 5,
        'job_level' => 'senior',
        'job_types' => ['full_time', 'remote'],
        'is_actively_seeking' => true,
    ])
        ->assertOk()
        ->assertJsonPath('message', 'Career information updated successfully')
        ->assertJsonPath('profile.current_job_title', 'Senior Frontend Developer')
        ->assertJsonPath('profile.is_actively_seeking', true);
});

test('career info validates job_level and years_of_experience', function (string $field, mixed $value) {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/career-info', [$field => $value])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => [$field]]);
})->with([
    'job_level'           => ['job_level', 'invalid_level'],
    'years_of_experience' => ['years_of_experience', 70],
]);

// ── Section: social links ──────────────────────────────────────────────────

test('a seeker can update social links', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/social-links', [
        'social_links' => ['linkedin' => 'https://linkedin.com/in/janedoe', 'github' => 'https://github.com/janedoe'],
    ])
        ->assertOk()
        ->assertJsonPath('profile.social_links.linkedin', 'https://linkedin.com/in/janedoe');
});

test('social links validates the url format', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/social-links', [
        'social_links' => ['linkedin' => 'not-a-url'],
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['social_links.linkedin']]);
});

test('social links requires the social_links object', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/social-links', [])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['social_links']]);
});

// ── Section: skills ────────────────────────────────────────────────────────

test('a seeker can update and delete skills', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [['name' => 'React', 'level' => 'advanced'], ['name' => 'Node.js', 'level' => 'beginner']],
    ])->assertOk()->assertJsonPath('message', 'Skills updated successfully');

    expect(seekerProfile()->skills)->toHaveCount(2);

    $this->withToken($this->token)->deleteJson('/api/job-seeker/profile/skills')
        ->assertOk()->assertJsonPath('message', 'Skills deleted successfully');

    expect(seekerProfile()->skills)->toHaveCount(0);
});

test('skills validate their level and require a name', function (array $skill, string $errorKey) {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', ['skills' => [$skill]])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => [$errorKey]]);
})->with([
    'bad level'    => [['name' => 'React', 'level' => 'invalid_level'], 'skills.0.level'],
    'missing name' => [['level' => 'advanced'], 'skills.0.name'],
]);

// ── Section: education & work experience ─────────────────────────────────

test('a seeker can update and delete education history', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/education', [
        'education_history' => [['university' => 'AUB', 'major' => 'Computer Science', 'from_date' => '2015-09']],
    ])->assertOk()->assertJsonPath('profile.education_history.0.university', 'AUB');

    $this->withToken($this->token)->deleteJson('/api/job-seeker/profile/education')
        ->assertOk()->assertJsonPath('message', 'Education history deleted successfully');
});

test('education history requires an array', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/education', [])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['education_history']]);
});

test('a seeker can update and delete work experience', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/work-experience', [
        'work_experience' => [
            ['job_title' => 'Senior Dev', 'company_name' => 'Acme Corp', 'from_date' => '2020-01', 'to_date' => '2023-06', 'is_currently_working' => false],
            ['job_title' => 'Dev', 'company_name' => 'Startup', 'from_date' => '2023-07', 'is_currently_working' => true],
        ],
    ])->assertOk()->assertJsonPath('profile.work_experience.0.company_name', 'Acme Corp');

    $this->withToken($this->token)->deleteJson('/api/job-seeker/profile/work-experience')
        ->assertOk()->assertJsonPath('message', 'Work experience deleted successfully');
});

test('work experience requires an array', function () {
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/work-experience', [])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['work_experience']]);
});

// ── Access control ─────────────────────────────────────────────────────────

test('an unauthenticated user cannot update a profile section', function () {
    $this->putJson('/api/job-seeker/profile/personal-info', ['full_name' => 'Hacker'])->assertUnauthorized();
});

test('a non-employee cannot update a job seeker profile', function () {
    $this->withToken(tokenFor('employer'))
        ->putJson('/api/job-seeker/profile/personal-info', ['full_name' => 'Should Fail'])
        ->assertForbidden();
});
