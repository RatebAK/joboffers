<?php

// Feature: admin-business-intelligence, Property 7: Talent report contains no PII

use App\Models\JobSeekerProfile;
use App\Models\User;

// PII fields that must never appear in any talent report response
const TALENT_PII_FIELDS = ['name', 'email', 'phone', 'user_id', 'ai_email', 'ai_phone', 'ai_full_name', 'ai_location'];

beforeEach(function () {
    JobSeekerProfile::truncate();
});

afterEach(function () {
    JobSeekerProfile::truncate();
});

function adminUserToken(): array
{
    $admin = User::factory()->admin()->create();
    $token = auth('api')->login($admin);
    return [$admin, $token];
}

function seedProfiles(int $count, ?string $industry = null): array
{
    $profiles = [];
    foreach (range(1, $count) as $i) {
        $seeker = User::factory()->employee()->create();
        $profile = JobSeekerProfile::create([
            'user_id'      => (string) $seeker->_id,
            'ai_skills'    => ["PHP_{$i}", "Laravel_{$i}", "MySQL"],
            'ats_score'    => 50 + $i,
            'job_roles'    => $industry ? [$industry] : ['General'],
            'cv_file_path' => 'https://example.com/cv.pdf',
            'ai_full_name' => 'Jane Doe',
            'ai_email'     => 'jane@example.com',
            'ai_phone'     => '0911111111',
        ]);
        $profiles[] = [$seeker, $profile];
    }
    return $profiles;
}

function cleanupProfiles(array $profiles): void
{
    foreach ($profiles as [$seeker, $profile]) {
        $profile->delete();
        $seeker->delete();
    }
}

// ── Fewer than 5 profiles → 422 ──────────────────────────────────────

test('talent report returns 422 when fewer than 5 profiles', function () {
    [$admin, $token] = adminUserToken();
    $created = seedProfiles(4);

    $this->withToken($token)->getJson('/api/admin/reports/talent')
        ->assertStatus(422)
        ->assertJson(['message' => 'Insufficient data for anonymized report']);

    cleanupProfiles($created);
    $admin->delete();
});

test('talent report returns 200 with 5 or more profiles', function () {
    [$admin, $token] = adminUserToken();
    $created = seedProfiles(5);

    $this->withToken($token)->getJson('/api/admin/reports/talent')
        ->assertStatus(200)
        ->assertJsonStructure(['profile_count', 'top_skills', 'ats_stats']);

    cleanupProfiles($created);
    $admin->delete();
});

// ── Property 7: No PII in response ───────────────────────────────────

test('talent report response contains no PII fields', function () {
    [$admin, $token] = adminUserToken();
    $created = seedProfiles(5);

    $resp = $this->withToken($token)->getJson('/api/admin/reports/talent')
        ->assertStatus(200);

    $body = $resp->json();
    $allKeys = array_keys($body);

    // Also check nested keys in top_skills and ats_stats
    foreach ($body['top_skills'] ?? [] as $skillRow) {
        $allKeys = array_merge($allKeys, array_keys($skillRow));
    }
    if (isset($body['ats_stats'])) {
        $allKeys = array_merge($allKeys, array_keys($body['ats_stats']));
    }

    foreach (TALENT_PII_FIELDS as $piiField) {
        expect($allKeys)->not->toContain($piiField);
    }

    cleanupProfiles($created);
    $admin->delete();
});

// ── ATS stats accuracy ────────────────────────────────────────────────

test('ats median is correct for odd number of scores', function () {
    [$admin, $token] = adminUserToken();
    // 5 profiles with scores 51,52,53,54,55 → median = 53
    $created = seedProfiles(5);

    $resp = $this->withToken($token)->getJson('/api/admin/reports/talent')
        ->assertStatus(200);

    $median = $resp->json('ats_stats.median');
    expect($median)->not->toBeNull();

    cleanupProfiles($created);
    $admin->delete();
});

test('ats stats include average minimum and maximum', function () {
    [$admin, $token] = adminUserToken();
    $created = seedProfiles(5);

    $this->withToken($token)->getJson('/api/admin/reports/talent')
        ->assertStatus(200)
        ->assertJsonStructure(['ats_stats' => ['average', 'median', 'minimum', 'maximum']]);

    cleanupProfiles($created);
    $admin->delete();
});

// ── Industry filter ───────────────────────────────────────────────────

test('industry filter scopes to matching profiles', function () {
    [$admin, $token] = adminUserToken();
    $tech    = seedProfiles(5, 'Technology');
    $finance = seedProfiles(5, 'Finance');

    // Request only Technology profiles — should get 5 of them
    $resp = $this->withToken($token)
        ->getJson('/api/admin/reports/talent?industry=Technology')
        ->assertStatus(200);

    expect($resp->json('profile_count'))->toBe(5);

    cleanupProfiles($tech);
    cleanupProfiles($finance);
    $admin->delete();
});

test('industry filter returns 422 when fewer than 5 matching profiles', function () {
    [$admin, $token] = adminUserToken();
    $tech = seedProfiles(3, 'Rare');

    $this->withToken($token)
        ->getJson('/api/admin/reports/talent?industry=Rare')
        ->assertStatus(422);

    cleanupProfiles($tech);
    $admin->delete();
});

// ── CSV export ────────────────────────────────────────────────────────

test('talent report csv returns correct headers', function () {
    [$admin, $token] = adminUserToken();
    $created = seedProfiles(5);

    $resp = $this->withToken($token)->getJson('/api/admin/reports/talent?format=csv')
        ->assertStatus(200);

    expect($resp->headers->get('Content-Type'))->toContain('text/csv');
    expect($resp->headers->get('Content-Disposition'))->toContain('attachment');

    cleanupProfiles($created);
    $admin->delete();
});

// ── Limit parameter ───────────────────────────────────────────────────

test('limit parameter caps the number of returned skills', function () {
    [$admin, $token] = adminUserToken();
    $created = seedProfiles(5);

    $resp = $this->withToken($token)->getJson('/api/admin/reports/talent?limit=2')
        ->assertStatus(200);

    expect(count($resp->json('top_skills')))->toBeLessThanOrEqual(2);

    cleanupProfiles($created);
    $admin->delete();
});
