<?php

// ============================================================
// DO NOT DELETE — Comprehensive tests for employer seeker search.
// Covers: all filter params (skills, ats range, location,
// keyword), pagination shape, show endpoint, 404 cases,
// and privacy (sensitive fields excluded from public profile).
// ============================================================

use App\Models\JobSeekerProfile;
use App\Models\User;

// ── Helpers ───────────────────────────────────────────────────

function searchEmployer(): array
{
    $employer = User::factory()->employer()->create();
    $token    = auth('api')->login($employer);
    return [$employer, $token];
}

function searchSeeker(array $profileOverrides = []): array
{
    $seeker = User::factory()->employee()->create();
    $profile = JobSeekerProfile::create(array_merge([
        'user_id'             => $seeker->_id,
        'is_actively_seeking' => true,
        'current_job_title'   => 'Software Engineer',
        'ai_skills'           => ['PHP', 'Laravel', 'MongoDB'],
        'ats_score'           => 70,
        'ai_location'         => 'Beirut, Lebanon',
        'ai_summary'          => 'Experienced backend developer.',
        'ai_email'            => 'private@example.com',
        'ai_phone'            => '+1234567890',
    ], $profileOverrides));
    return [$seeker, $profile];
}

// ── Index: GET /api/employer/seekers ─────────────────────────

test('employer can list actively seeking job seekers', function () {
    [$employer, $token] = searchEmployer();
    [$seeker, $profile] = searchSeeker();

    $response = $this->withToken($token)->getJson('/api/employer/seekers')
                     ->assertStatus(200)
                     ->assertJsonStructure(['seekers']);

    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('non-actively-seeking seekers are excluded from results', function () {
    [$employer, $token] = searchEmployer();
    [$seeker, $profile] = searchSeeker(['is_actively_seeking' => false]);

    $response = $this->withToken($token)->getJson('/api/employer/seekers')->assertStatus(200);
    $ids = collect($response->json('seekers.data'))->pluck('user_id')->toArray();
    expect($ids)->not->toContain((string) $seeker->_id);

    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('filter seekers by single skill', function () {
    [$employer, $token] = searchEmployer();
    [$seeker, $profile] = searchSeeker(['ai_skills' => ['Vue.js', 'JavaScript']]);

    $response = $this->withToken($token)->getJson('/api/employer/seekers?skills=Vue.js')
                     ->assertStatus(200);

    foreach ($response->json('seekers.data') as $s) {
        $skills = array_map('strtolower', $s['ai_skills'] ?? []);
        expect($skills)->toContain('vue.js');
    }

    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('filter seekers by multiple comma-separated skills', function () {
    [$employer, $token] = searchEmployer();
    [$seeker, $profile] = searchSeeker(['ai_skills' => ['React', 'TypeScript', 'Node.js']]);

    $response = $this->withToken($token)->getJson('/api/employer/seekers?skills=React,TypeScript')
                     ->assertStatus(200);

    foreach ($response->json('seekers.data') as $s) {
        $skills = array_map('strtolower', $s['ai_skills'] ?? []);
        expect($skills)->toContain('react');
        expect($skills)->toContain('typescript');
    }

    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('filter seekers by min_ats_score excludes lower scores', function () {
    [$employer, $token] = searchEmployer();
    [$highSeeker, $highProfile] = searchSeeker(['ats_score' => 85]);
    [$lowSeeker, $lowProfile]   = searchSeeker(['ats_score' => 40]);

    $response = $this->withToken($token)->getJson('/api/employer/seekers?min_ats_score=80')
                     ->assertStatus(200);

    foreach ($response->json('seekers.data') as $s) {
        expect($s['ats_score'])->toBeGreaterThanOrEqual(80);
    }

    $highProfile->delete(); $highSeeker->delete();
    $lowProfile->delete(); $lowSeeker->delete();
    $employer->delete();
});

test('filter seekers by max_ats_score excludes higher scores', function () {
    [$employer, $token] = searchEmployer();
    [$highSeeker, $highProfile] = searchSeeker(['ats_score' => 95]);
    [$lowSeeker, $lowProfile]   = searchSeeker(['ats_score' => 50]);

    $response = $this->withToken($token)->getJson('/api/employer/seekers?max_ats_score=60')
                     ->assertStatus(200);

    foreach ($response->json('seekers.data') as $s) {
        expect($s['ats_score'])->toBeLessThanOrEqual(60);
    }

    $highProfile->delete(); $highSeeker->delete();
    $lowProfile->delete(); $lowSeeker->delete();
    $employer->delete();
});

test('filter seekers by location partial match', function () {
    [$employer, $token] = searchEmployer();
    [$seeker, $profile] = searchSeeker(['ai_location' => 'Dubai, UAE']);

    $response = $this->withToken($token)->getJson('/api/employer/seekers?location=Dubai')
                     ->assertStatus(200);

    $found = collect($response->json('seekers.data'))
        ->first(fn($s) => str_contains(strtolower($s['ai_location'] ?? ''), 'dubai'));
    expect($found)->not->toBeNull();

    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('filter seekers by keyword matches current_job_title', function () {
    [$employer, $token] = searchEmployer();
    [$seeker, $profile] = searchSeeker(['current_job_title' => 'UniqueDevTitle123']);

    $response = $this->withToken($token)->getJson('/api/employer/seekers?keyword=UniqueDevTitle123')
                     ->assertStatus(200);

    $found = collect($response->json('seekers.data'))
        ->first(fn($s) => str_contains($s['current_job_title'] ?? '', 'UniqueDevTitle123'));
    expect($found)->not->toBeNull();

    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('filter seekers by keyword matches ai_summary', function () {
    [$employer, $token] = searchEmployer();
    [$seeker, $profile] = searchSeeker(['ai_summary' => 'XYZ_UNIQUE_SUMMARY_TERM expert developer.']);

    $response = $this->withToken($token)->getJson('/api/employer/seekers?keyword=XYZ_UNIQUE_SUMMARY_TERM')
                     ->assertStatus(200);

    $found = collect($response->json('seekers.data'))
        ->first(fn($s) => str_contains($s['ai_summary'] ?? '', 'XYZ_UNIQUE_SUMMARY_TERM'));
    expect($found)->not->toBeNull();

    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('seeker search returns paginated response', function () {
    [$employer, $token] = searchEmployer();

    $response = $this->withToken($token)->getJson('/api/employer/seekers')
                     ->assertStatus(200)
                     ->assertJsonStructure(['seekers' => ['data', 'current_page', 'per_page', 'total']]);

    $employer->delete();
});

// ── Show: GET /api/employer/seekers/{userId} ──────────────────

test('employer can view a seeker public profile', function () {
    [$employer, $token] = searchEmployer();
    [$seeker, $profile] = searchSeeker();

    $this->withToken($token)->getJson("/api/employer/seekers/{$seeker->_id}")
         ->assertStatus(200)
         ->assertJsonStructure(['seeker' => ['user_id', 'name', 'profile']]);

    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('seeker public profile excludes ai_email and ai_phone', function () {
    [$employer, $token] = searchEmployer();
    [$seeker, $profile] = searchSeeker();

    $response = $this->withToken($token)->getJson("/api/employer/seekers/{$seeker->_id}")
                     ->assertStatus(200);

    $profileData = $response->json('seeker.profile');
    expect($profileData)->not->toHaveKey('ai_email');
    expect($profileData)->not->toHaveKey('ai_phone');

    $profile->delete(); $seeker->delete(); $employer->delete();
});

test('seeker show returns 404 for non-existent user', function () {
    [$employer, $token] = searchEmployer();

    $this->withToken($token)->getJson('/api/employer/seekers/000000000000000000000000')
         ->assertStatus(404);

    $employer->delete();
});

test('seeker show returns 404 when user exists but has no profile', function () {
    [$employer, $token] = searchEmployer();
    $seeker = User::factory()->employee()->create();

    $this->withToken($token)->getJson("/api/employer/seekers/{$seeker->_id}")
         ->assertStatus(404);

    $seeker->delete(); $employer->delete();
});

test('seeker show returns 404 when user is not a job seeker', function () {
    [$employer, $token] = searchEmployer();
    [$otherEmployer]    = searchEmployer();

    $this->withToken($token)->getJson("/api/employer/seekers/{$otherEmployer->_id}")
         ->assertStatus(404);

    $otherEmployer->delete(); $employer->delete();
});

// ── Auth guard ────────────────────────────────────────────────

test('unauthenticated user cannot search seekers', function () {
    $this->getJson('/api/employer/seekers')->assertStatus(401);
});

test('job seeker cannot access seeker search endpoint', function () {
    $seeker = User::factory()->employee()->create();
    $token  = auth('api')->login($seeker);

    $this->withToken($token)->getJson('/api/employer/seekers')->assertStatus(403);

    $seeker->delete();
});
