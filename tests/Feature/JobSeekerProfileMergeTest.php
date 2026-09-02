<?php

// =============================================================================
// JobSeekerProfileMergeTest
//
// The section-update endpoints perform a partial merge: updating one section
// must never clobber the others, omitted fields keep their values, and a GET
// immediately reflects the latest write.
// =============================================================================

use App\Models\JobSeekerProfile;

beforeEach(function () {
    [$this->seeker, $this->token] = userWithToken('employee');
});

/** Update a profile section for the current seeker. */
function updateSection(string $section, array $payload): void
{
    test()->withToken(test()->token)
        ->putJson("/api/job-seeker/profile/{$section}", $payload)
        ->assertOk();
}

function mergeProfile(): JobSeekerProfile
{
    return JobSeekerProfile::where('user_id', (string) test()->seeker->_id)->first();
}

test('updating one section preserves the others', function () {
    updateSection('career-info', ['current_job_title' => 'Developer', 'years_of_experience' => 5]);
    updateSection('personal-info', ['full_name' => 'Jane Doe', 'phone' => '+961 70 123456']);
    updateSection('skills', ['skills' => [['name' => 'React', 'level' => 'advanced']]]);

    $profile = mergeProfile();
    expect($profile->full_name)->toBe('Jane Doe')
        ->and($profile->current_job_title)->toBe('Developer')
        ->and($profile->years_of_experience)->toBe(5)
        ->and($profile->skills)->toHaveCount(1);
});

test('omitted fields are not cleared on a partial update', function () {
    updateSection('personal-info', ['full_name' => 'Jane Doe', 'phone' => '+961 70 123456', 'city' => 'Beirut']);
    updateSection('personal-info', ['phone' => '+961 71 999888']);

    $profile = mergeProfile();
    expect($profile->phone)->toBe('+961 71 999888')
        ->and($profile->full_name)->toBe('Jane Doe')
        ->and($profile->city)->toBe('Beirut');
});

test('a GET immediately reflects a section update', function () {
    updateSection('career-info', ['current_job_title' => 'Senior Developer', 'years_of_experience' => 5, 'job_level' => 'senior']);

    $this->withToken($this->token)->getJson('/api/job-seeker/profile')
        ->assertOk()
        ->assertJsonPath('profile.current_job_title', 'Senior Developer')
        ->assertJsonPath('profile.job_level', 'senior');
});

test('a GET reflects deleted skills as an empty array', function () {
    updateSection('skills', ['skills' => [['name' => 'React', 'level' => 'advanced']]]);
    $this->withToken($this->token)->deleteJson('/api/job-seeker/profile/skills')->assertOk();

    $skills = $this->withToken($this->token)->getJson('/api/job-seeker/profile')->assertOk()->json('profile.skills');

    expect($skills)->toBeArray()->toHaveCount(0);
});

test('a full multi-section setup is retained and readable', function () {
    updateSection('personal-info', ['full_name' => 'Complete User']);
    updateSection('career-info', ['current_job_title' => 'Developer']);
    updateSection('skills', ['skills' => [['name' => 'PHP', 'level' => 'expert']]]);
    updateSection('work-experience', ['work_experience' => [['job_title' => 'Developer', 'company_name' => 'Acme']]]);

    $profile = $this->withToken($this->token)->getJson('/api/job-seeker/profile')
        ->assertOk()
        ->assertJsonPath('profile.full_name', 'Complete User')
        ->assertJsonPath('profile.current_job_title', 'Developer')
        ->json('profile');

    expect($profile['skills'])->toHaveCount(1)
        ->and($profile['work_experience'][0]['company_name'])->toBe('Acme');
});

test('the update response matches a subsequent GET', function () {
    $put = $this->withToken($this->token)
        ->putJson('/api/job-seeker/profile/personal-info', ['full_name' => 'Test Match', 'phone' => '+961 70 111111'])
        ->assertOk()
        ->json('profile');

    $get = $this->withToken($this->token)->getJson('/api/job-seeker/profile')->assertOk()->json('profile');

    expect($get['full_name'])->toBe($put['full_name'])
        ->and($get['phone'])->toBe($put['phone']);
});
