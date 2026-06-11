<?php

use App\Models\JobSeekerProfile;
use App\Models\User;
use function Pest\Laravel\{putJson, deleteJson};

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

// Personal Information Tests
test('can update personal information', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'full_name' => 'Jane Doe',
        'gender' => 'female',
        'nationality' => 'Lebanese',
        'city' => 'Beirut',
        'location' => 'Beirut, Lebanon',
        'phone' => '+961 70 123456',
        'date_of_birth' => '1995-06-15',
        'marital_status' => 'single',
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Personal information updated successfully',
        ])
        ->assertJsonPath('profile.full_name', 'Jane Doe')
        ->assertJsonPath('profile.phone', '+961 70 123456');
    
    // Verify data was actually saved to database
    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->full_name)->toBe('Jane Doe');
    expect($profile->first_name)->toBe('Jane');
    expect($profile->last_name)->toBe('Doe');
    expect($profile->phone)->toBe('+961 70 123456');
    expect($profile->gender)->toBe('female');
    expect($profile->city)->toBe('Beirut');
});

test('personal info validates phone length', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'phone' => str_repeat('1', 21), // 21 characters
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['errors' => ['phone']]);
});

test('personal info validates gender enum', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'gender' => 'invalid_gender',
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['errors' => ['gender']]);
});

// Career Information Tests
test('can update career information', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/career-info', [
        'salary_range_from' => 2000,
        'salary_range_to' => 5000,
        'current_job_status' => 'employed',
        'years_of_experience' => 5,
        'education_level' => "Bachelor's Degree",
        'job_level' => 'senior',
        'job_types' => ['full_time', 'remote'],
        'job_roles' => ['Frontend', 'React'],
        'work_cities' => ['Beirut', 'Dubai'],
        'current_job_title' => 'Senior Frontend Developer',
        'expected_salary' => 3500,
        'is_actively_seeking' => true,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Career information updated successfully',
        ])
        ->assertJsonPath('profile.current_job_title', 'Senior Frontend Developer')
        ->assertJsonPath('profile.years_of_experience', 5)
        ->assertJsonPath('profile.is_actively_seeking', true);
    
    // Verify data was actually saved to database
    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->current_job_title)->toBe('Senior Frontend Developer');
    expect($profile->years_of_experience)->toBe(5);
    expect($profile->job_level)->toBe('senior');
    expect($profile->job_types)->toBe(['full_time', 'remote']);
    expect($profile->is_actively_seeking)->toBeTrue();
});

test('career info validates job level enum', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/career-info', [
        'job_level' => 'invalid_level',
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['errors' => ['job_level']]);
});

test('career info validates years of experience range', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/career-info', [
        'years_of_experience' => 70, // Max is 60
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['errors' => ['years_of_experience']]);
});

// Social Links Tests
test('can update social links', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/social-links', [
        'social_links' => [
            'linkedin' => 'https://linkedin.com/in/janedoe',
            'github' => 'https://github.com/janedoe',
            'portfolio' => 'https://janedoe.dev',
            'twitter' => 'https://twitter.com/janedoe',
        ],
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Social links updated successfully',
        ])
        ->assertJsonPath('profile.social_links.linkedin', 'https://linkedin.com/in/janedoe')
        ->assertJsonPath('profile.social_links.github', 'https://github.com/janedoe');
});

test('social links validates URL format', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/social-links', [
        'social_links' => [
            'linkedin' => 'not-a-url',
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['errors' => ['social_links.linkedin']]);
});

test('social links requires social_links object', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/social-links', []);

    $response->assertStatus(422)
        ->assertJsonStructure(['errors' => ['social_links']]);
});

// Skills Tests
test('can update skills', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [
            ['name' => 'React', 'level' => 'advanced'],
            ['name' => 'TypeScript', 'level' => 'intermediate'],
            ['name' => 'Node.js', 'level' => 'beginner'],
        ],
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Skills updated successfully',
        ]);
    
    // Verify data was actually saved to database
    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->skills)->toBeArray();
    expect($profile->skills)->toHaveCount(3);
    expect($profile->skills[0]['name'])->toBe('React');
    expect($profile->skills[0]['level'])->toBe('advanced');
    expect($profile->skills[1]['name'])->toBe('TypeScript');
    expect($profile->skills[2]['name'])->toBe('Node.js');
});

test('can delete all skills', function () {
    // First create skills
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [
            ['name' => 'React', 'level' => 'advanced'],
        ],
    ]);

    // Verify skills were created
    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile->skills)->toHaveCount(1);

    // Then delete them
    $response = $this->withToken($this->token)->deleteJson('/api/job-seeker/profile/skills');

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Skills deleted successfully',
        ]);
    
    // Verify skills were actually deleted
    $profile->refresh();
    expect($profile->skills)->toBeArray();
    expect($profile->skills)->toHaveCount(0);
});

test('skills validates level enum', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [
            ['name' => 'React', 'level' => 'invalid_level'],
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['errors' => ['skills.0.level']]);
});

test('skills requires name field', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [
            ['level' => 'advanced'], // missing name
        ],
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['errors' => ['skills.0.name']]);
});

// Education History Tests
test('can update education history', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/education', [
        'education_history' => [
            [
                'certificate_type' => "Bachelor's Degree",
                'university' => 'American University of Beirut',
                'faculty' => 'Engineering',
                'major' => 'Computer Science',
                'grade' => '3.8 GPA',
                'from_date' => '2015-09',
                'awarded_date' => '2019-06',
            ],
        ],
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Education history updated successfully',
        ]);
    
    $education = $response->json('profile.education_history');
    expect($education)->toHaveCount(1);
    expect($education[0]['university'])->toBe('American University of Beirut');
    expect($education[0]['major'])->toBe('Computer Science');
});

test('can delete education history', function () {
    // First create education
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/education', [
        'education_history' => [
            ['university' => 'AUB', 'major' => 'CS'],
        ],
    ]);

    // Then delete
    $response = $this->withToken($this->token)->deleteJson('/api/job-seeker/profile/education');

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Education history deleted successfully',
        ]);
});

test('education history requires array', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/education', []);

    $response->assertStatus(422)
        ->assertJsonStructure(['errors' => ['education_history']]);
});

// Work Experience Tests
test('can update work experience', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/work-experience', [
        'work_experience' => [
            [
                'job_title' => 'Senior Frontend Developer',
                'company_name' => 'Acme Corp',
                'job_roles' => ['React', 'TypeScript'],
                'from_date' => '2020-01',
                'to_date' => '2023-06',
                'is_currently_working' => false,
                'description' => 'Led frontend development team',
            ],
            [
                'job_title' => 'Frontend Developer',
                'company_name' => 'Tech Startup',
                'from_date' => '2023-07',
                'is_currently_working' => true,
            ],
        ],
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Work experience updated successfully',
        ]);
    
    $experience = $response->json('profile.work_experience');
    expect($experience)->toHaveCount(2);
    expect($experience[0]['company_name'])->toBe('Acme Corp');
    expect($experience[1]['is_currently_working'])->toBeTrue();
});

test('can delete work experience', function () {
    // First create work experience
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/work-experience', [
        'work_experience' => [
            ['job_title' => 'Developer', 'company_name' => 'Acme'],
        ],
    ]);

    // Then delete
    $response = $this->withToken($this->token)->deleteJson('/api/job-seeker/profile/work-experience');

    $response->assertStatus(200)
        ->assertJson([
            'message' => 'Work experience deleted successfully',
        ]);
});

test('work experience requires array', function () {
    $response = $this->withToken($this->token)->putJson('/api/job-seeker/profile/work-experience', []);

    $response->assertStatus(422)
        ->assertJsonStructure(['errors' => ['work_experience']]);
});

// Integration Tests
test('can update multiple sections independently', function () {
    // Update personal info
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/personal-info', [
        'full_name' => 'Jane Doe',
    ]);

    // Update career info
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/career-info', [
        'current_job_title' => 'Developer',
    ]);

    // Update skills
    $this->withToken($this->token)->putJson('/api/job-seeker/profile/skills', [
        'skills' => [['name' => 'React', 'level' => 'advanced']],
    ]);

    // Verify all sections are preserved
    $profile = JobSeekerProfile::where('user_id', (string) $this->seeker->_id)->first();
    expect($profile->full_name)->toBe('Jane Doe');
    expect($profile->current_job_title)->toBe('Developer');
    expect($profile->skills)->toHaveCount(1);
});

test('unauthorized user cannot update profile', function () {
    $response = $this->putJson('/api/job-seeker/profile/personal-info', [
        'full_name' => 'Hacker',
    ]);

    $response->assertStatus(401);
});

test('non-employee cannot update profile', function () {
    $employer = User::factory()->employer()->create();
    $employerToken = auth('api')->login($employer);

    $response = $this->withToken($employerToken)->putJson('/api/job-seeker/profile/personal-info', [
        'full_name' => 'Should Fail',
    ]);

    $response->assertStatus(403);
});
