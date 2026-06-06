<?php

use App\Models\JobSeekerProfile;
use App\Models\User;
use function Pest\Laravel\{postJson, getJson};

beforeEach(function () {
    // Clean up existing data
    User::truncate();
    JobSeekerProfile::truncate();
    
    // Create job seekers with different profiles using factory
    $this->seeker1 = User::factory()->employee()->create([
        'name' => 'Alice Johnson',
        'email' => 'alice@example.com',
    ]);

    JobSeekerProfile::create([
        'user_id' => (string) $this->seeker1->_id,
        'current_job_title' => 'Senior React Developer',
        'ai_full_name' => 'Alice Johnson',
        'ai_summary' => 'Experienced frontend developer specializing in React and TypeScript',
        'ai_skills' => ['React', 'TypeScript', 'Node.js', 'JavaScript'],
        'ai_location' => 'Beirut, Lebanon',
        'ats_score' => 85,
        'years_of_experience' => 5,
        'job_level' => 'senior',
        'is_actively_seeking' => true,
    ]);

    $this->seeker2 = User::factory()->employee()->create([
        'name' => 'Bob Smith',
        'email' => 'bob@example.com',
    ]);

    JobSeekerProfile::create([
        'user_id' => (string) $this->seeker2->_id,
        'current_job_title' => 'Junior Python Developer',
        'ai_full_name' => 'Bob Smith',
        'ai_summary' => 'Entry-level backend developer with Python and Django experience',
        'ai_skills' => ['Python', 'Django', 'PostgreSQL', 'REST API'],
        'ai_location' => 'Tripoli, Lebanon',
        'ats_score' => 65,
        'years_of_experience' => 2,
        'job_level' => 'junior',
        'is_actively_seeking' => false,
    ]);

    $this->seeker3 = User::factory()->employee()->create([
        'name' => 'Carol White',
        'email' => 'carol@example.com',
    ]);

    JobSeekerProfile::create([
        'user_id' => (string) $this->seeker3->_id,
        'current_job_title' => 'Full Stack Engineer',
        'ai_full_name' => 'Carol White',
        'ai_summary' => 'Versatile full-stack engineer with React and Node.js expertise',
        'ai_skills' => ['React', 'Node.js', 'MongoDB', 'Express'],
        'ai_location' => 'Beirut, Lebanon',
        'ats_score' => 92,
        'years_of_experience' => 7,
        'job_level' => 'senior',
        'is_actively_seeking' => true,
    ]);
});

afterEach(function () {
    // Clean up after tests
    User::truncate();
    JobSeekerProfile::truncate();
});

test('can search users with general search term', function () {
    $response = getJson('/api/search/users?search=React');

    $response->assertStatus(200)
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'user_id',
                    'name',
                    'current_job_title',
                    'ai_skills',
                    'ats_score',
                ]
            ],
            'current_page',
            'per_page',
            'total',
        ]);

    expect($response->json('total'))->toBeGreaterThanOrEqual(2);
});

test('can filter by skills', function () {
    $response = getJson('/api/search/users?skills=Python,Django');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['current_job_title'])->toBe('Junior Python Developer');
});

test('can filter by minimum experience', function () {
    $response = getJson('/api/search/users?min_experience=5');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    expect(count($data))->toBe(2);
    
    foreach ($data as $profile) {
        expect($profile['years_of_experience'])->toBeGreaterThanOrEqual(5);
    }
});

test('can filter by experience range', function () {
    $response = getJson('/api/search/users?min_experience=3&max_experience=6');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['years_of_experience'])->toBe(5);
});

test('can filter by minimum ATS score', function () {
    $response = getJson('/api/search/users?min_ats_score=80');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    expect(count($data))->toBe(2);
    
    foreach ($data as $profile) {
        expect($profile['ats_score'])->toBeGreaterThanOrEqual(80);
    }
});

test('can filter by ATS score range', function () {
    $response = getJson('/api/search/users?min_ats_score=70&max_ats_score=90');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['ats_score'])->toBe(85);
});

test('can filter by location', function () {
    $response = getJson('/api/search/users?location=Tripoli');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['ai_location'])->toContain('Tripoli');
});

test('can filter by job level', function () {
    $response = getJson('/api/search/users?job_level=senior');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    expect(count($data))->toBe(2);
    
    foreach ($data as $profile) {
        expect(strtolower($profile['job_level']))->toBe('senior');
    }
});

test('can filter by actively seeking status', function () {
    $response = getJson('/api/search/users?actively_seeking=true');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    expect(count($data))->toBe(2);
    
    foreach ($data as $profile) {
        expect($profile['is_actively_seeking'])->toBeTrue();
    }
});

test('can combine multiple filters', function () {
    $response = getJson('/api/search/users?skills=React&min_ats_score=90&location=Beirut');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Carol White');
    expect($data[0]['ats_score'])->toBe(92);
});

test('results are ordered by ATS score descending', function () {
    $response = getJson('/api/search/users');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    $scores = array_column($data, 'ats_score');
    
    // Verify scores are in descending order
    for ($i = 0; $i < count($scores) - 1; $i++) {
        expect($scores[$i])->toBeGreaterThanOrEqual($scores[$i + 1]);
    }
});

test('sensitive fields are not exposed in search results', function () {
    $response = getJson('/api/search/users');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    
    foreach ($data as $profile) {
        expect($profile)->not->toHaveKey('ai_email');
        expect($profile)->not->toHaveKey('ai_phone');
        expect($profile)->not->toHaveKey('ai_phone_number');
    }
});

test('supports pagination', function () {
    $response = getJson('/api/search/users?per_page=2&page=1');

    $response->assertStatus(200)
        ->assertJson([
            'current_page' => 1,
            'per_page' => 2,
            'total_pages' => 2,
        ]);
    
    expect(count($response->json('data')))->toBeLessThanOrEqual(2);
});

test('pagination respects max per_page limit', function () {
    $response = getJson('/api/search/users?per_page=200');

    $response->assertStatus(200);
    expect($response->json('per_page'))->toBeLessThanOrEqual(100);
});

test('returns empty results when no matches found', function () {
    $response = getJson('/api/search/users?skills=Rust,Haskell&min_ats_score=99');

    $response->assertStatus(200)
        ->assertJson([
            'data' => [],
            'total' => 0,
        ]);
});

test('general search matches against name', function () {
    $response = getJson('/api/search/users?search=Carol');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['name'])->toBe('Carol White');
});

test('general search matches against job title', function () {
    $response = getJson('/api/search/users?search=Full Stack');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['current_job_title'])->toContain('Full Stack');
});

test('general search matches against summary', function () {
    $response = getJson('/api/search/users?search=backend');

    $response->assertStatus(200);
    
    $data = $response->json('data');
    expect(count($data))->toBeGreaterThanOrEqual(1);
});
