<?php

/**
 * Simple smoke test for user search API
 */

use App\Models\JobSeekerProfile;
use App\Models\User;
use function Pest\Laravel\getJson;

beforeEach(function () {
    User::truncate();
    JobSeekerProfile::truncate();
});

afterEach(function () {
    User::truncate();
    JobSeekerProfile::truncate();
});

test('user search endpoint is publicly accessible', function () {
    $response = getJson('/api/search/users');
    
    $response->assertStatus(200)
        ->assertJsonStructure([
            'data',
            'current_page',
            'per_page',
            'total',
            'total_pages',
            'next_page',
            'prev_page',
        ]);
});

test('user search returns job seeker profiles', function () {
    $user = User::factory()->employee()->create(['name' => 'Test Developer']);
    
    JobSeekerProfile::create([
        'user_id' => (string) $user->_id,
        'current_job_title' => 'Software Engineer',
        'ai_skills' => ['PHP', 'Laravel'],
        'ats_score' => 80,
    ]);
    
    $response = getJson('/api/search/users');
    
    $response->assertStatus(200);
    expect($response->json('total'))->toBeGreaterThanOrEqual(1);
    expect($response->json('data'))->toBeArray();
});

test('user search filters by skills', function () {
    $user1 = User::factory()->employee()->create();
    JobSeekerProfile::create([
        'user_id' => (string) $user1->_id,
        'ai_skills' => ['React', 'JavaScript'],
        'ats_score' => 75,
    ]);
    
    $user2 = User::factory()->employee()->create();
    JobSeekerProfile::create([
        'user_id' => (string) $user2->_id,
        'ai_skills' => ['Python', 'Django'],
        'ats_score' => 80,
    ]);
    
    $response = getJson('/api/search/users?skills=React');
    
    $response->assertStatus(200);
    $data = $response->json('data');
    
    expect(count($data))->toBeGreaterThanOrEqual(1);
    expect($data[0]['ai_skills'])->toContain('React');
});

test('user search filters by ATS score range', function () {
    $user1 = User::factory()->employee()->create();
    JobSeekerProfile::create([
        'user_id' => (string) $user1->_id,
        'ats_score' => 60,
        'ai_skills' => ['PHP'],
    ]);
    
    $user2 = User::factory()->employee()->create();
    JobSeekerProfile::create([
        'user_id' => (string) $user2->_id,
        'ats_score' => 90,
        'ai_skills' => ['PHP'],
    ]);
    
    $response = getJson('/api/search/users?min_ats_score=85');
    
    $response->assertStatus(200);
    $data = $response->json('data');
    
    foreach ($data as $profile) {
        expect($profile['ats_score'])->toBeGreaterThanOrEqual(85);
    }
});
