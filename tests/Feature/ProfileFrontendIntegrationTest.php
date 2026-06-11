<?php

use App\Models\JobSeekerProfile;
use App\Models\User;
use function Pest\Laravel\{putJson, getJson, deleteJson};

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

test('frontend workflow: complete profile setup', function () {
    // Step 1: Get initial profile (should be empty or auto-created)
    $getResponse = $this->withToken($this->token)->getJson('/api/job-seeker/profile');
    $getResponse->assertStatus(200);
    
    // Step 2: Update personal information
    $personalResponse = $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'full_name' => 'John Doe',
        'phone' => '+961 70 123456',
        'city' => 'Beirut',
        'gender' => 'male',
    ]);
    
    $personalResponse->assertStatus(200);
    
    // Immediately verify it was saved
    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->full_name)->toBe('John Doe');
    expect($profile->phone)->toBe('+961 70 123456');
    
    // Step 3: Update career information
    $careerResponse = $this->withToken($this->token)->putJson('/api/job-seeker/profile/career-info', [
        'current_job_title' => 'Senior Developer',
        'years_of_experience' => 5,
        'job_level' => 'senior',
        'is_actively_seeking' => true,
    ]);
    
    $careerResponse->assertStatus(200);
    
    // Verify career info was saved AND personal info is still there
    $profile->refresh();
    expect($profile->current_job_title)->toBe('Senior Developer');
    expect($profile->years_of_experience)->toBe(5);
    expect($profile->full_name)->toBe('John Doe'); // Should still be there
    expect($profile->phone)->toBe('+961 70 123456'); // Should still be there
    
    // Step 4: Add skills
    $skillsResponse = $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [
            ['name' => 'React', 'level' => 'advanced'],
            ['name' => 'Node.js', 'level' => 'intermediate'],
        ],
    ]);
    
    $skillsResponse->assertStatus(200);
    
    // Verify all data is still intact
    $profile->refresh();
    expect($profile->skills)->toHaveCount(2);
    expect($profile->full_name)->toBe('John Doe');
    expect($profile->current_job_title)->toBe('Senior Developer');
    
    // Step 5: Add work experience
    $experienceResponse = $this->withToken($this->token)->putJson('/api/job-seeker/profile/work-experience', [
        'work_experience' => [
            [
                'job_title' => 'Developer',
                'company_name' => 'Acme Corp',
                'from_date' => '2020-01',
                'to_date' => '2023-06',
                'is_currently_working' => false,
            ],
        ],
    ]);
    
    $experienceResponse->assertStatus(200);
    
    // Final verification - everything should be there
    $profile->refresh();
    expect($profile->full_name)->toBe('John Doe');
    expect($profile->current_job_title)->toBe('Senior Developer');
    expect($profile->skills)->toHaveCount(2);
    expect($profile->work_experience)->toHaveCount(1);
    expect($profile->work_experience[0]['company_name'])->toBe('Acme Corp');
    
    // Step 6: Get complete profile
    $finalGet = $this->withToken($this->token)->getJson('/api/job-seeker/profile');
    $finalGet->assertStatus(200)
        ->assertJsonPath('profile.full_name', 'John Doe')
        ->assertJsonPath('profile.current_job_title', 'Senior Developer');
});

test('frontend workflow: update existing profile', function () {
    // Create initial profile with all sections
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'full_name' => 'Jane Smith',
        'phone' => '+961 70 111111',
    ]);
    
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/career-info', [
        'current_job_title' => 'Junior Developer',
        'years_of_experience' => 2,
    ]);
    
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [['name' => 'JavaScript', 'level' => 'beginner']],
    ]);
    
    // Verify initial state
    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile->full_name)->toBe('Jane Smith');
    expect($profile->current_job_title)->toBe('Junior Developer');
    expect($profile->skills)->toHaveCount(1);
    
    // Now user updates their career (got promoted!)
    $updateResponse = $this->withToken($this->token)->putJson('/api/job-seeker/profile/career-info', [
        'current_job_title' => 'Senior Developer',
        'years_of_experience' => 2, // Same
        'job_level' => 'senior', // New
    ]);
    
    $updateResponse->assertStatus(200);
    
    // Verify career was updated AND other sections preserved
    $profile->refresh();
    expect($profile->current_job_title)->toBe('Senior Developer');
    expect($profile->job_level)->toBe('senior');
    expect($profile->full_name)->toBe('Jane Smith'); // Should be preserved
    expect($profile->skills)->toHaveCount(1); // Should be preserved
});

test('frontend workflow: delete and re-add skills', function () {
    // Add initial skills
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [
            ['name' => 'PHP', 'level' => 'beginner'],
            ['name' => 'Laravel', 'level' => 'beginner'],
        ],
    ]);
    
    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile->skills)->toHaveCount(2);
    
    // User decides to delete all skills
    $deleteResponse = $this->withToken($this->token)->deleteJson('/api/job-seeker/profile/skills');
    $deleteResponse->assertStatus(200);
    
    $profile->refresh();
    expect($profile->skills)->toBeArray();
    expect($profile->skills)->toHaveCount(0);
    
    // User adds new skills
    $addResponse = $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [
            ['name' => 'React', 'level' => 'advanced'],
            ['name' => 'TypeScript', 'level' => 'advanced'],
            ['name' => 'Node.js', 'level' => 'intermediate'],
        ],
    ]);
    
    $addResponse->assertStatus(200);
    
    $profile->refresh();
    expect($profile->skills)->toHaveCount(3);
    expect($profile->skills[0]['name'])->toBe('React');
});

test('response includes updated data immediately', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'full_name' => 'Test User',
        'phone' => '+961 70 999999',
    ]);
    
    $response->assertStatus(200)
        ->assertJsonPath('profile.full_name', 'Test User')
        ->assertJsonPath('profile.phone', '+961 70 999999');
    
    // Response should match database
    $responseData = $response->json('profile');
    $dbProfile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    
    expect($responseData['full_name'])->toBe($dbProfile->full_name);
    expect($responseData['phone'])->toBe($dbProfile->phone);
});
