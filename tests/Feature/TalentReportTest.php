<?php

// Anonymized talent market report (GET /api/admin/reports/talent).
// Property 7: the report contains no PII.

use App\Models\JobSeekerProfile;

// PII fields that must never appear in any talent report response.
const TALENT_PII_FIELDS = ['name', 'email', 'phone', 'user_id', 'ai_email', 'ai_phone', 'ai_full_name', 'ai_location'];

/** Seed $count job seeker profiles, optionally tagged with an industry (job_roles). */
function seedProfiles(int $count, ?string $industry = null): void
{
    foreach (range(1, $count) as $i) {
        createSeekerWithProfile([], [
            'ai_skills'    => ["PHP_{$i}", "Laravel_{$i}", 'MySQL'],
            'ats_score'    => 50 + $i,
            'job_roles'    => $industry ? [$industry] : ['General'],
            'cv_file_path' => 'https://example.com/cv.pdf',
            'ai_full_name' => 'Jane Doe',
            'ai_email'     => 'jane@example.com',
            'ai_phone'     => '0911111111',
        ]);
    }
}

beforeEach(function () {
    [$this->admin, $this->adminToken] = userWithToken('admin');
});

// ── Anonymity gate (min 5 profiles) ──────────────────────────────────

test('talent report returns 422 when fewer than 5 profiles', function () {
    seedProfiles(4);

    $this->withToken($this->adminToken)->getJson('/api/admin/reports/talent')
        ->assertStatus(422)
        ->assertJson(['message' => 'Insufficient data for anonymized report']);
});

test('talent report returns 200 with 5 or more profiles', function () {
    seedProfiles(5);

    $this->withToken($this->adminToken)->getJson('/api/admin/reports/talent')
        ->assertOk()
        ->assertJsonStructure(['profile_count', 'top_skills', 'ats_stats']);
});

// ── Property 7: no PII in response ───────────────────────────────────

test('talent report response contains no PII fields', function () {
    seedProfiles(5);

    $body = $this->withToken($this->adminToken)->getJson('/api/admin/reports/talent')
        ->assertOk()
        ->json();

    $allKeys = array_keys($body);
    foreach ($body['top_skills'] ?? [] as $skillRow) {
        $allKeys = array_merge($allKeys, array_keys($skillRow));
    }
    if (isset($body['ats_stats'])) {
        $allKeys = array_merge($allKeys, array_keys($body['ats_stats']));
    }

    foreach (TALENT_PII_FIELDS as $piiField) {
        expect($allKeys)->not->toContain($piiField);
    }
});

// ── ATS statistics ───────────────────────────────────────────────────

test('ats median is present for an odd number of scores', function () {
    seedProfiles(5);

    $median = $this->withToken($this->adminToken)->getJson('/api/admin/reports/talent')
        ->assertOk()
        ->json('ats_stats.median');

    expect($median)->not->toBeNull();
});

test('ats stats include average, median, minimum, and maximum', function () {
    seedProfiles(5);

    $this->withToken($this->adminToken)->getJson('/api/admin/reports/talent')
        ->assertOk()
        ->assertJsonStructure(['ats_stats' => ['average', 'median', 'minimum', 'maximum']]);
});

// ── Industry filter ──────────────────────────────────────────────────

test('industry filter scopes to matching profiles', function () {
    seedProfiles(5, 'Technology');
    seedProfiles(5, 'Finance');

    $this->withToken($this->adminToken)
        ->getJson('/api/admin/reports/talent?industry=Technology')
        ->assertOk()
        ->assertJsonPath('profile_count', 5);
});

test('industry filter returns 422 when fewer than 5 matching profiles', function () {
    seedProfiles(3, 'Rare');

    $this->withToken($this->adminToken)
        ->getJson('/api/admin/reports/talent?industry=Rare')
        ->assertStatus(422);
});

// ── CSV export ───────────────────────────────────────────────────────

test('talent report csv returns csv headers', function () {
    seedProfiles(5);

    $resp = $this->withToken($this->adminToken)->getJson('/api/admin/reports/talent?format=csv')
        ->assertOk();

    expect($resp->headers->get('Content-Type'))->toContain('text/csv')
        ->and($resp->headers->get('Content-Disposition'))->toContain('attachment');
});

// ── Limit parameter ──────────────────────────────────────────────────

test('limit parameter caps the number of returned skills', function () {
    seedProfiles(5);

    $skills = $this->withToken($this->adminToken)->getJson('/api/admin/reports/talent?limit=2')
        ->assertOk()
        ->json('top_skills');

    expect(count($skills))->toBeLessThanOrEqual(2);
});
