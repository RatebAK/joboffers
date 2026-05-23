<?php

// ============================================================
// DO NOT DELETE — Tests for job seeker profile CRUD covering
// all fields exposed by the API (personal info, career info,
// education, work experience, skills, social links).
// Also covers company profile CRUD (create → read → update → delete).
// ============================================================

use App\Models\CompanyProfile;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;

// ── Helpers ───────────────────────────────────────────────────

function makeProfileSeeker(): array
{
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);
    return [$seeker, $token];
}

function makeProfileEmployer(): array
{
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);
    return [$employer, $token];
}

function fullProfilePayload(): array
{
    return [
        // Personal
        'first_name'    => 'John',
        'last_name'     => 'Doe',
        'full_name'     => 'John Doe',
        'image'         => 'https://api.dicebear.com/7.x/avataaars/svg?seed=John',
        'gender'        => 'male',
        'nationality'   => 'American',
        'city'          => 'New York',
        'location'      => 'New York, USA',
        'address'       => '123 Main Street, Apt 4B',
        'phone'         => '+1 (555) 123-4567',
        'date_of_birth' => '1990-05-15',
        'marital_status' => 'single',
        // Career
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
        // Social links
        'social_links' => [
            'linkedin'  => 'https://linkedin.com/in/johndoe',
            'github'    => 'https://github.com/johndoe',
            'portfolio' => 'https://johndoe.dev',
            'twitter'   => 'https://twitter.com/johndoe',
        ],
        // Skills
        'skills' => [
            ['name' => 'React',      'level' => 'expert'],
            ['name' => 'TypeScript', 'level' => 'advanced'],
            ['name' => 'Node.js',    'level' => 'advanced'],
            ['name' => 'Next.js',    'level' => 'intermediate'],
        ],
        // Education
        'education_history' => [
            [
                'certificate_type' => 'bachelor',
                'university'       => 'harvard',
                'faculty'          => 'engineering',
                'major'            => 'computer-science',
                'major_name'       => 'Computer Science',
                'grade'            => 'excellent',
                'from_date'        => '2015-09',
                'awarded_date'     => '2019-06',
            ],
        ],
        // Work experience
        'work_experience' => [
            [
                'job_title'            => 'senior-software-engineer',
                'company_name'         => 'Tech Corp',
                'job_roles'            => ['frontend', 'team-lead'],
                'from_date'            => '2020-01',
                'to_date'              => '',
                'is_currently_working' => true,
                'description'          => 'Leading a team of 5 developers.',
            ],
        ],
    ];
}

// ── Job Seeker Profile — GET ──────────────────────────────────

test('job seeker can fetch their profile (auto-created if missing)', function () {
    [$seeker, $token] = makeProfileSeeker();

    $response = $this->withToken($token)->getJson('/api/job-seeker/profile');

    $response->assertStatus(200)->assertJsonStructure(['profile']);

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

// ── Job Seeker Profile — PUT (full payload) ───────────────────

test('job seeker can update profile with all fields', function () {
    [$seeker, $token] = makeProfileSeeker();

    $response = $this->withToken($token)->putJson('/api/job-seeker/profile', fullProfilePayload());

    $response->assertStatus(200)
             ->assertJsonPath('profile.first_name', 'John')
             ->assertJsonPath('profile.last_name', 'Doe')
             ->assertJsonPath('profile.city', 'New York')
             ->assertJsonPath('profile.gender', 'male')
             ->assertJsonPath('profile.nationality', 'American')
             ->assertJsonPath('profile.marital_status', 'single')
             ->assertJsonPath('profile.phone', '+1 (555) 123-4567')
             ->assertJsonPath('profile.date_of_birth', '1990-05-15')
             ->assertJsonPath('profile.salary_range_from', 80000)
             ->assertJsonPath('profile.salary_range_to', 120000)
             ->assertJsonPath('profile.current_job_status', 'employed')
             ->assertJsonPath('profile.years_of_experience', 5)
             ->assertJsonPath('profile.education_level', 'bachelor')
             ->assertJsonPath('profile.job_level', 'mid');

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('job seeker profile stores job_types, job_roles, work_cities arrays', function () {
    [$seeker, $token] = makeProfileSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/profile', fullProfilePayload());

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->job_types)->toBe(['full-time', 'remote']);
    expect($profile->job_roles)->toBe(['frontend', 'fullstack']);
    expect($profile->work_cities)->toBe(['new-york', 'remote']);

    $profile->delete();
    $seeker->delete();
});

test('job seeker profile stores social_links as nested object', function () {
    [$seeker, $token] = makeProfileSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/profile', fullProfilePayload());

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect($profile->social_links['linkedin'])->toBe('https://linkedin.com/in/johndoe');
    expect($profile->social_links['github'])->toBe('https://github.com/johndoe');
    expect($profile->social_links['portfolio'])->toBe('https://johndoe.dev');
    expect($profile->social_links['twitter'])->toBe('https://twitter.com/johndoe');

    $profile->delete();
    $seeker->delete();
});

test('job seeker profile stores skills with name and level', function () {
    [$seeker, $token] = makeProfileSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/profile', fullProfilePayload());

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    expect(count($profile->skills))->toBe(4);
    expect($profile->skills[0]['name'])->toBe('React');
    expect($profile->skills[0]['level'])->toBe('expert');

    $profile->delete();
    $seeker->delete();
});

test('job seeker profile stores education_history with all fields', function () {
    [$seeker, $token] = makeProfileSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/profile', fullProfilePayload());

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    $edu = $profile->education_history[0];
    expect($edu['certificate_type'])->toBe('bachelor');
    expect($edu['university'])->toBe('harvard');
    expect($edu['major_name'])->toBe('Computer Science');
    expect($edu['grade'])->toBe('excellent');
    expect($edu['from_date'])->toBe('2015-09');
    expect($edu['awarded_date'])->toBe('2019-06');

    $profile->delete();
    $seeker->delete();
});

test('job seeker profile stores work_experience with all fields', function () {
    [$seeker, $token] = makeProfileSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/profile', fullProfilePayload());

    $profile = JobSeekerProfile::where('user_id', $seeker->_id)->first();
    $exp = $profile->work_experience[0];
    expect($exp['job_title'])->toBe('senior-software-engineer');
    expect($exp['company_name'])->toBe('Tech Corp');
    expect($exp['job_roles'])->toBe(['frontend', 'team-lead']);
    expect((bool) $exp['is_currently_working'])->toBeTrue();

    $profile->delete();
    $seeker->delete();
});

test('job seeker profile update rejects invalid gender', function () {
    [$seeker, $token] = makeProfileSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/profile', ['gender' => 'robot'])
         ->assertStatus(422)->assertJsonStructure(['errors' => ['gender']]);

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('job seeker profile update rejects invalid job_level', function () {
    [$seeker, $token] = makeProfileSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/profile', ['job_level' => 'god'])
         ->assertStatus(422)->assertJsonStructure(['errors' => ['job_level']]);

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('job seeker profile update rejects invalid skill level', function () {
    [$seeker, $token] = makeProfileSeeker();

    $this->withToken($token)->putJson('/api/job-seeker/profile', [
        'skills' => [['name' => 'PHP', 'level' => 'godlike']],
    ])->assertStatus(422);

    JobSeekerProfile::where('user_id', $seeker->_id)->delete();
    $seeker->delete();
});

test('unauthenticated user cannot access job seeker profile', function () {
    $this->getJson('/api/job-seeker/profile')->assertStatus(401);
});

// ── Company Profile — Full CRUD ───────────────────────────────

test('employer can create a company profile', function () {
    [$employer, $token] = makeProfileEmployer();

    $response = $this->withToken($token)->postJson('/api/employer/company', [
        'name'         => 'Acme Inc',
        'description'  => 'We build great things.',
        'location'     => 'San Francisco, CA',
        'company_size' => ['min' => 100, 'max' => 500, 'isPlus' => false],
        'industry'     => 'Technology',
        'website'      => 'https://acme.com',
        'logo'         => 'https://acme.com/logo.png',
    ]);

    $response->assertStatus(200)
             ->assertJsonPath('name', 'Acme Inc')
             ->assertJsonPath('industry', 'Technology')
             ->assertJsonPath('location', 'San Francisco, CA')
             ->assertJsonPath('company_size', '100-500')
             ->assertJsonStructure(['open_positions', 'company_size_range']);

    CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('employer can read their company profile via public endpoint', function () {
    [$employer, $token] = makeProfileEmployer();

    $profile = CompanyProfile::create([
        'employer_id'  => (string) $employer->_id,
        'name'         => 'ReadMe Corp',
        'industry'     => 'Finance',
        'location'     => 'London, UK',
        'company_size' => '50-100',
        'website'      => 'https://readme.com',
        'rating'       => 4.2,
        'review_count' => 80,
    ]);

    $this->getJson("/api/companies/{$profile->_id}")
         ->assertStatus(200)
         ->assertJsonPath('name', 'ReadMe Corp')
         ->assertJsonPath('industry', 'Finance')
         ->assertJsonStructure(['open_positions', 'company_size_range']);

    $profile->delete();
    $employer->delete();
});

test('employer can update their company profile', function () {
    [$employer, $token] = makeProfileEmployer();

    CompanyProfile::create([
        'employer_id' => (string) $employer->_id,
        'name'        => 'Old Name',
        'industry'    => 'Retail',
    ]);

    $response = $this->withToken($token)->putJson('/api/employer/company', [
        'name'         => 'New Name',
        'industry'     => 'Technology',
        'company_size' => ['min' => 500, 'isPlus' => true],
    ]);

    $response->assertStatus(200)
             ->assertJsonPath('name', 'New Name')
             ->assertJsonPath('industry', 'Technology')
             ->assertJsonPath('company_size', '500+')
             ->assertJsonPath('company_size_range.isPlus', true);

    CompanyProfile::where('employer_id', (string) $employer->_id)->delete();
    $employer->delete();
});

test('company profile upsert requires name', function () {
    [$employer, $token] = makeProfileEmployer();

    $this->withToken($token)->postJson('/api/employer/company', [
        'industry' => 'Tech',
    ])->assertStatus(422)->assertJsonStructure(['errors' => ['name']]);

    $employer->delete();
});

test('company profile show returns 404 for unknown id', function () {
    $this->getJson('/api/companies/000000000000000000000000')
         ->assertStatus(404);
});

test('company profile includes open_positions count', function () {
    [$employer, $token] = makeProfileEmployer();

    $profile = CompanyProfile::create([
        'employer_id' => (string) $employer->_id,
        'name'        => 'Jobs Corp',
        'industry'    => 'Tech',
    ]);

    // Create one active job post
    $job = JobPost::create([
        'title'        => 'Dev',
        'description'  => 'Code.',
        'requirements' => 'PHP.',
        'company_name' => 'Jobs Corp',
        'job_type'     => 'full_time',
        'location'     => 'Remote',
        'employer_id'  => (string) $employer->_id,
        'is_active'    => true,
    ]);

    $response = $this->getJson("/api/companies/{$profile->_id}");
    $response->assertStatus(200);
    expect($response->json('open_positions'))->toBeGreaterThanOrEqual(1);

    $job->delete();
    $profile->delete();
    $employer->delete();
});

test('non-employer cannot create company profile', function () {
    [$seeker, $token] = makeProfileSeeker();

    $this->withToken($token)->postJson('/api/employer/company', [
        'name' => 'Sneaky Corp',
    ])->assertStatus(403);

    $seeker->delete();
});
