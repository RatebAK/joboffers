<?php

use App\Models\JobSeekerProfile;
use App\Models\User;
use function Pest\Laravel\{putJson, getJson};

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

test('GET profile after PUT personal info returns updated data', function () {
    // Update personal info
    $putResponse = $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'full_name' => 'Jane Doe',
        'phone' => '+961 70 123456',
        'city' => 'Beirut',
    ]);
    
    $putResponse->assertStatus(200);
    
    // Immediately GET the profile
    $getResponse = $this->withToken($this->token)->getJson('/api/job-seeker/profile');
    
    $getResponse->assertStatus(200)
        ->assertJsonPath('profile.full_name', 'Jane Doe')
        ->assertJsonPath('profile.phone', '+961 70 123456')
        ->assertJsonPath('profile.city', 'Beirut');
});

test('GET profile after PUT career info returns updated data', function () {
    $putResponse = $this->withToken($this->token)->putJson('/api/job-seeker/profile/career-info', [
        'current_job_title' => 'Senior Developer',
        'years_of_experience' => 5,
        'job_level' => 'senior',
    ]);
    
    $putResponse->assertStatus(200);
    
    $getResponse = $this->withToken($this->token)->getJson('/api/job-seeker/profile');
    
    $getResponse->assertStatus(200)
        ->assertJsonPath('profile.current_job_title', 'Senior Developer')
        ->assertJsonPath('profile.years_of_experience', 5)
        ->assertJsonPath('profile.job_level', 'senior');
});

test('GET profile after PUT skills returns updated data', function () {
    $putResponse = $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [
            ['name' => 'React', 'level' => 'advanced'],
            ['name' => 'Node.js', 'level' => 'intermediate'],
        ],
    ]);
    
    $putResponse->assertStatus(200);
    
    $getResponse = $this->withToken($this->token)->getJson('/api/job-seeker/profile');
    
    $getResponse->assertStatus(200);
    
    $skills = $getResponse->json('profile.skills');
    expect($skills)->toHaveCount(2);
    expect($skills[0]['name'])->toBe('React');
    expect($skills[1]['name'])->toBe('Node.js');
});

test('GET profile after DELETE skills returns empty array', function () {
    // First add skills
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [['name' => 'React', 'level' => 'advanced']],
    ]);
    
    // Delete skills
    $deleteResponse = $this->withToken($this->token)->deleteJson('/api/job-seeker/profile/skills');
    $deleteResponse->assertStatus(200);
    
    // GET profile
    $getResponse = $this->withToken($this->token)->getJson('/api/job-seeker/profile');
    
    $getResponse->assertStatus(200);
    
    $skills = $getResponse->json('profile.skills');
    expect($skills)->toBeArray();
    expect($skills)->toHaveCount(0);
});

test('GET profile after multiple updates returns all data', function () {
    // Update personal
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'full_name' => 'Complete User',
    ]);
    
    // Update career
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/career-info', [
        'current_job_title' => 'Developer',
    ]);
    
    // Update skills
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [['name' => 'PHP', 'level' => 'expert']],
    ]);
    
    // Update work experience
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/work-experience', [
        'work_experience' => [
            ['job_title' => 'Developer', 'company_name' => 'Acme'],
        ],
    ]);
    
    // GET profile - should have everything
    $getResponse = $this->withToken($this->token)->getJson('/api/job-seeker/profile');
    
    $getResponse->assertStatus(200)
        ->assertJsonPath('profile.full_name', 'Complete User')
        ->assertJsonPath('profile.current_job_title', 'Developer');
    
    $profile = $getResponse->json('profile');
    expect($profile['skills'])->toHaveCount(1);
    expect($profile['work_experience'])->toHaveCount(1);
    expect($profile['skills'][0]['name'])->toBe('PHP');
    expect($profile['work_experience'][0]['company_name'])->toBe('Acme');
});

test('PUT response matches immediate GET response', function () {
    // Update personal info
    $putResponse = $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'full_name' => 'Test Match',
        'phone' => '+961 70 111111',
    ]);
    
    $putData = $putResponse->json('profile');
    
    // Immediately GET
    $getResponse = $this->withToken($this->token)->getJson('/api/job-seeker/profile');
    $getData = $getResponse->json('profile');
    
    // They should match
    expect($getData['full_name'])->toBe($putData['full_name']);
    expect($getData['phone'])->toBe($putData['phone']);
    expect($getData['full_name'])->toBe('Test Match');
    expect($getData['phone'])->toBe('+961 70 111111');
});
