<?php

// Admin business-intelligence reports: churn, funnel, pipeline, categories.
// Property 4: estimated lost revenue is exact.
// Property 5: top-category ordering matches the sort_by parameter.

use App\Models\Application;
use App\Models\Employer;
use App\Models\JobPost;

/** Create a pending employer application for a fresh employee user (not covered by shared helpers). */
function pendingEmployer(array $attributes = []): Employer
{
    $user = createUser('employee');

    return Employer::create(array_merge([
        'user_id'    => (string) $user->_id,
        'status'     => 'pending',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ], $attributes));
}

beforeEach(function () {
    [$this->admin, $this->adminToken] = userWithToken('admin');
});

// ── GET /api/admin/reports/churn ─────────────────────────────────────

test('churn report requires authentication', function () {
    $this->getJson('/api/admin/reports/churn')->assertUnauthorized();
});

test('non-admin cannot view the churn report', function () {
    $this->withToken(tokenFor('employee'))
        ->getJson('/api/admin/reports/churn')
        ->assertForbidden();
});

test('churn report defaults window_days to 30', function () {
    $resp = $this->withToken($this->adminToken)->getJson('/api/admin/reports/churn')
        ->assertOk()
        ->assertJsonStructure(['window_days', 'employers', 'seekers']);

    expect($resp->json('window_days'))->toBe(30);
});

test('churn report falls back to 30 for an invalid window_days', function () {
    $resp = $this->withToken($this->adminToken)->getJson('/api/admin/reports/churn?window_days=999')
        ->assertOk();

    expect($resp->json('window_days'))->toBe(30);
});

test('churn report csv returns csv headers', function () {
    $resp = $this->withToken($this->adminToken)->getJson('/api/admin/reports/churn?format=csv')
        ->assertOk();

    expect($resp->headers->get('Content-Type'))->toContain('text/csv')
        ->and($resp->headers->get('Content-Disposition'))->toContain('attachment');
});

test('an active employer with a recent post is excluded from churn', function () {
    $employer = createUser('employer');
    createJob($employer, ['title' => 'Recent Job', 'category' => 'Tech', 'created_at' => now()->subDays(5)]);

    $resp = $this->withToken($this->adminToken)->getJson('/api/admin/reports/churn?window_days=30')
        ->assertOk();

    $ids = collect($resp->json('employers'))->pluck('user_id')->toArray();
    expect($ids)->not->toContain((string) $employer->_id);
});

test('a seeker with a cv but no application appears in churn', function () {
    [$seeker] = createSeekerWithProfile([], ['cv_file_path' => 'https://example.com/cv.pdf']);

    $resp = $this->withToken($this->adminToken)->getJson('/api/admin/reports/churn')
        ->assertOk();

    $ids = collect($resp->json('seekers'))->pluck('user_id')->toArray();
    expect($ids)->toContain((string) $seeker->_id);
});

// ── GET /api/admin/reports/funnel ────────────────────────────────────

test('funnel returns all four stages in order', function () {
    $resp = $this->withToken($this->adminToken)->getJson('/api/admin/reports/funnel')
        ->assertOk()
        ->assertJsonStructure(['stages']);

    $stages = collect($resp->json('stages'))->pluck('stage')->toArray();
    expect($stages)->toBe(['registered', 'cv_uploaded', 'applied', 'hired']);
});

test('funnel stage counts are monotonically non-increasing', function () {
    $stages = $this->withToken($this->adminToken)->getJson('/api/admin/reports/funnel')
        ->assertOk()
        ->json('stages');

    for ($i = 1; $i < count($stages); $i++) {
        expect($stages[$i]['count'])->toBeLessThanOrEqual($stages[$i - 1]['count']);
    }
});

// ── GET /api/admin/reports/pipeline ──────────────────────────────────

test('pipeline report returns pending employer stats', function () {
    $this->withToken($this->adminToken)->getJson('/api/admin/reports/pipeline')
        ->assertOk()
        ->assertJsonStructure(['pending_count', 'avg_wait_days', 'estimated_lost_revenue', 'employers']);
});

// Property 4: estimated lost revenue = pending_count x avg_wait_days x rate
test('estimated lost revenue is exact', function () {
    pendingEmployer();
    pendingEmployer();

    $data = $this->withToken($this->adminToken)
        ->getJson('/api/admin/reports/pipeline?daily_revenue_per_employer=10')
        ->assertOk()
        ->json();

    $expected = round($data['pending_count'] * $data['avg_wait_days'] * $data['daily_revenue_per_employer'], 2);

    expect((float) $data['estimated_lost_revenue'])->toBe($expected);
});

// ── GET /api/admin/reports/categories ────────────────────────────────

// Property 5: default ordering is by application count, descending
test('categories are sorted by application count descending by default', function () {
    $employer = createUser('employer');
    $postA = createJob($employer, ['title' => 'A', 'category' => 'Alpha']);
    $postB = createJob($employer, ['title' => 'B', 'category' => 'Beta']);
    $seeker = createUser('employee');

    // 3 applications for Beta, 1 for Alpha
    Application::create(['user_id' => (string) $seeker->_id, 'job_post_id' => (string) $postB->_id, 'status' => 'pending']);
    Application::create(['user_id' => (string) $seeker->_id, 'job_post_id' => (string) $postB->_id, 'status' => 'pending']);
    Application::create(['user_id' => (string) $seeker->_id, 'job_post_id' => (string) $postB->_id, 'status' => 'pending']);
    Application::create(['user_id' => (string) $seeker->_id, 'job_post_id' => (string) $postA->_id, 'status' => 'pending']);

    $cats = collect($this->withToken($this->adminToken)->getJson('/api/admin/reports/categories')
        ->assertOk()
        ->json('categories'));

    for ($i = 1; $i < $cats->count(); $i++) {
        expect($cats[$i]['application_count'])->toBeLessThanOrEqual($cats[$i - 1]['application_count']);
    }

    $names    = $cats->pluck('category')->toArray();
    $betaIdx  = array_search('Beta', $names);
    $alphaIdx = array_search('Alpha', $names);
    expect($betaIdx)->toBeLessThan($alphaIdx);
});

test('categories are sorted by post count when sort_by=posts', function () {
    $cats = collect($this->withToken($this->adminToken)->getJson('/api/admin/reports/categories?sort_by=posts')
        ->assertOk()
        ->json('categories'));

    for ($i = 1; $i < $cats->count(); $i++) {
        expect($cats[$i]['post_count'])->toBeLessThanOrEqual($cats[$i - 1]['post_count']);
    }
});

test('categories limit parameter is respected', function () {
    $count = count($this->withToken($this->adminToken)->getJson('/api/admin/reports/categories?limit=3')
        ->assertOk()
        ->json('categories'));

    expect($count)->toBeLessThanOrEqual(3);
});
