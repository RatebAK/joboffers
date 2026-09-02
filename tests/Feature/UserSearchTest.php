<?php

// =============================================================================
// UserSearchTest — GET /api/search/users (public talent search).
//
// Covers the general search term, each filter, filter combinations, ordering,
// pagination, and that sensitive AI fields are never exposed.
// =============================================================================

beforeEach(function () {
    createSeekerWithProfile(['name' => 'Alice Johnson'], [
        'ai_full_name'        => 'Alice Johnson',
        'current_job_title'   => 'Senior React Developer',
        'ai_summary'          => 'Experienced frontend developer specializing in React and TypeScript',
        'ai_skills'           => ['React', 'TypeScript', 'Node.js'],
        'ai_location'         => 'Beirut, Lebanon',
        'ats_score'           => 85,
        'years_of_experience' => 5,
        'job_level'           => 'senior',
        'is_actively_seeking' => true,
    ]);

    createSeekerWithProfile(['name' => 'Bob Smith'], [
        'ai_full_name'        => 'Bob Smith',
        'current_job_title'   => 'Junior Python Developer',
        'ai_summary'          => 'Entry-level backend developer with Python and Django experience',
        'ai_skills'           => ['Python', 'Django', 'PostgreSQL'],
        'ai_location'         => 'Tripoli, Lebanon',
        'ats_score'           => 65,
        'years_of_experience' => 2,
        'job_level'           => 'junior',
        'is_actively_seeking' => false,
    ]);

    createSeekerWithProfile(['name' => 'Carol White'], [
        'ai_full_name'        => 'Carol White',
        'current_job_title'   => 'Full Stack Engineer',
        'ai_summary'          => 'Versatile full-stack engineer with React and Node.js expertise',
        'ai_skills'           => ['React', 'Node.js', 'MongoDB'],
        'ai_location'         => 'Beirut, Lebanon',
        'ats_score'           => 92,
        'years_of_experience' => 7,
        'job_level'           => 'senior',
        'is_actively_seeking' => true,
    ]);
});

test('the search endpoint is public and returns the standard shape', function () {
    $this->getJson('/api/search/users')
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['*' => ['id', 'user_id', 'name', 'current_job_title', 'ai_skills', 'ats_score']],
            'current_page', 'per_page', 'total', 'total_pages', 'next_page', 'prev_page',
        ]);
});

test('a general search term matches name, job title, and summary', function (string $term, int $expectedAtLeast) {
    expect($this->getJson("/api/search/users?search={$term}")->assertOk()->json('total'))
        ->toBeGreaterThanOrEqual($expectedAtLeast);
})->with([
    'name'    => ['Carol', 1],
    'title'   => ['Full Stack', 1],
    'summary' => ['backend', 1],
    'skill'   => ['React', 2],
]);

test('results can be filtered by skills', function () {
    $data = $this->getJson('/api/search/users?skills=Python,Django')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['current_job_title'])->toBe('Junior Python Developer');
});

test('results can be filtered by an experience range', function () {
    $data = $this->getJson('/api/search/users?min_experience=3&max_experience=6')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['years_of_experience'])->toBe(5);
});

test('results can be filtered by an ATS score range', function () {
    $data = $this->getJson('/api/search/users?min_ats_score=70&max_ats_score=90')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['ats_score'])->toBe(85);
});

test('results can be filtered by location', function () {
    $data = $this->getJson('/api/search/users?location=Tripoli')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['ai_location'])->toContain('Tripoli');
});

test('results can be filtered by job level', function () {
    $data = $this->getJson('/api/search/users?job_level=senior')->assertOk()->json('data');

    expect(collect($data)->pluck('job_level')->map('strtolower')->unique()->all())->toBe(['senior']);
});

test('results can be filtered by actively-seeking status', function () {
    $data = $this->getJson('/api/search/users?actively_seeking=true')->assertOk()->json('data');

    expect(count($data))->toBe(2)
        ->and(collect($data)->pluck('is_actively_seeking')->every(fn ($v) => $v === true))->toBeTrue();
});

test('filters can be combined', function () {
    $data = $this->getJson('/api/search/users?skills=React&min_ats_score=90&location=Beirut')->assertOk()->json('data');

    expect($data)->toHaveCount(1)
        ->and($data[0]['name'])->toBe('Carol White');
});

test('results are ordered by ATS score descending', function () {
    $scores = array_column($this->getJson('/api/search/users')->assertOk()->json('data'), 'ats_score');

    expect($scores)->toBe(collect($scores)->sortDesc()->values()->all());
});

test('sensitive AI contact fields are never exposed', function () {
    foreach ($this->getJson('/api/search/users')->assertOk()->json('data') as $profile) {
        expect($profile)->not->toHaveKeys(['ai_email', 'ai_phone', 'ai_phone_number']);
    }
});

test('search supports pagination and caps per_page at 100', function () {
    $this->getJson('/api/search/users?per_page=2&page=1')
        ->assertOk()
        ->assertJsonPath('current_page', 1)
        ->assertJsonPath('per_page', 2);

    expect($this->getJson('/api/search/users?per_page=200')->assertOk()->json('per_page'))
        ->toBeLessThanOrEqual(100);
});

test('search returns an empty result set when nothing matches', function () {
    $this->getJson('/api/search/users?skills=Rust,Haskell&min_ats_score=99')
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('total', 0);
});
