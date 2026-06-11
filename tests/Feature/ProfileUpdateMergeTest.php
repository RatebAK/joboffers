<?php

use App\Models\JobSeekerProfile;
use App\Models\User;
use function Pest\Laravel\putJson;

beforeEach(function () {
    User::truncate();
    JobSeekerProfile::truncate();
    
    $this->seeker = User::factory()->employee()->create();
    $this->token = auth('api')->login($this->seeker);
});

afterEach(function () {
    User::truncate();
    JobSeekerProfile::truncate();
});

test('updating personal info preserves career info', function () {
    // First, set career info
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/career-info', [
        'current_job_title' => 'Developer',
        'years_of_experience' => 5,
    ]);

    // Verify career info was saved
    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile->current_job_title)->toBe('Developer');
    expect($profile->years_of_experience)->toBe(5);

    // Now update personal info
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'full_name' => 'Jane Doe',
        'phone' => '+961 70 123456',
    ]);

    // Verify personal info was saved
    $profile->refresh();
    expect($profile->full_name)->toBe('Jane Doe');
    expect($profile->phone)->toBe('+961 70 123456');

    // CRITICAL: Verify career info was NOT overwritten
    expect($profile->current_job_title)->toBe('Developer');
    expect($profile->years_of_experience)->toBe(5);
});

test('updating skills preserves personal and career info', function () {
    // Set personal info
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'full_name' => 'Jane Doe',
    ]);

    // Set career info
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/career-info', [
        'current_job_title' => 'Developer',
    ]);

    // Verify both were saved
    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile->full_name)->toBe('Jane Doe');
    expect($profile->current_job_title)->toBe('Developer');

    // Now update skills
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [['name' => 'React', 'level' => 'advanced']],
    ]);

    // Verify skills were saved
    $profile->refresh();
    expect($profile->skills)->toHaveCount(1);

    // CRITICAL: Verify other sections were NOT overwritten
    expect($profile->full_name)->toBe('Jane Doe');
    expect($profile->current_job_title)->toBe('Developer');
});

test('empty values do not overwrite existing data', function () {
    // Set initial data
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'full_name' => 'Jane Doe',
        'phone' => '+961 70 123456',
        'city' => 'Beirut',
    ]);

    // Verify data was saved
    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile->full_name)->toBe('Jane Doe');
    expect($profile->phone)->toBe('+961 70 123456');
    expect($profile->city)->toBe('Beirut');

    // Update only phone (don't send full_name or city)
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'phone' => '+961 71 999888',
    ]);

    // Verify phone was updated
    $profile->refresh();
    expect($profile->phone)->toBe('+961 71 999888');

    // CRITICAL: Verify other fields were NOT cleared
    expect($profile->full_name)->toBe('Jane Doe');
    expect($profile->city)->toBe('Beirut');
});
