<?php

// Feature: admin-business-intelligence
// Property 4: Estimated lost revenue calculation is exact
// Property 5: Top categories sort order matches sort_by parameter

use App\Models\Application;
use App\Models\Employer;
use App\Models\JobPost;
use App\Models\JobSeekerProfile;
use App\Models\User;

// ── Helpers ──────────────────────────────────────────────────────────

function adminToken(): array
{
    $admin = User::factory()->admin()->create();
    $token = auth('api')->login($admin);
    return [$admin, $token];
}

// ── GET /api/admin/reports/churn ─────────────────────────────────────

test('churn report returns 401 without token', function () {
    $this->getJson('/api/admin/reports/churn')->assertStatus(401);
});

test('churn report returns 403 for non-admin', function () {
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    $this->withToken($token)->getJson('/api/admin/reports/churn')->assertStatus(403);
    $user->delete();
});

test('churn report defaults window_days to 30', function () {
    [$admin, $token] = adminToken();

    $resp = $this->withToken($token)->getJson('/api/admin/reports/churn')
        ->assertStatus(200)
        ->assertJsonStructure(['window_days', 'employers', 'seekers']);

    expect($resp->json('window_days'))->toBe(30);

    $admin->delete();
});

test('churn report defaults to 30 when invalid window_days provided', function () {
    [$admin, $token] = adminToken();

    $resp = $this->withToken($token)->getJson('/api/admin/reports/churn?window_days=999')
        ->assertStatus(200);

    expect($resp->json('window_days'))->toBe(30);

    $admin->delete();
});

test('churn report csv returns correct content-type and disposition headers', function () {
    [$admin, $token] = adminToken();

    $resp = $this->withToken($token)->getJson('/api/admin/reports/churn?format=csv')
        ->assertStatus(200);

    expect($resp->headers->get('Content-Type'))->toContain('text/csv');
    expect($resp->headers->get('Content-Disposition'))->toContain('attachment');

    $admin->delete();
});

test('active employer with recent post is excluded from churn report', function () {
    [$admin, $token] = adminToken();
    $employer = User::factory()->employer()->create();

    JobPost::create([
        'employer_id' => (string) $employer->_id,
        'title'       => 'Recent Job',
        'is_active'   => true,
        'category'    => 'Tech',
        'created_at'  => now()->subDays(5),
    ]);

    $resp = $this->withToken($token)->getJson('/api/admin/reports/churn?window_days=30')
        ->assertStatus(200);

    $ids = collect($resp->json('employers'))->pluck('user_id')->toArray();
    expect($ids)->not->toContain((string) $employer->_id);

    $employer->delete();
    JobPost::where('employer_id', (string) $employer->_id)->delete();
    $admin->delete();
});

test('seeker with cv but no application appears in churn report', function () {
    [$admin, $token] = adminToken();
    $seeker = User::factory()->employee()->create();
    JobSeekerProfile::create([
        'user_id'      => (string) $seeker->_id,
        'cv_file_path' => 'https://example.com/cv.pdf',
    ]);

    $resp = $this->withToken($token)->getJson('/api/admin/reports/churn')
        ->assertStatus(200);

    $ids = collect($resp->json('seekers'))->pluck('user_id')->toArray();
    expect($ids)->toContain((string) $seeker->_id);

    JobSeekerProfile::where('user_id', (string) $seeker->_id)->delete();
    $seeker->delete();
    $admin->delete();
});

// ── GET /api/admin/reports/funnel ─────────────────────────────────────

test('funnel returns all four stages in order', function () {
    [$admin, $token] = adminToken();

    $resp = $this->withToken($token)->getJson('/api/admin/reports/funnel')
        ->assertStatus(200)
        ->assertJsonStructure(['stages']);

    $stages = collect($resp->json('stages'))->pluck('stage')->toArray();
    expect($stages)->toBe(['registered', 'cv_uploaded', 'applied', 'hired']);

    $admin->delete();
});

test('funnel stage counts are monotonically non-increasing', function () {
    [$admin, $token] = adminToken();

    $resp  = $this->withToken($token)->getJson('/api/admin/reports/funnel')->assertStatus(200);
    $stages = $resp->json('stages');

    for ($i = 1; $i < count($stages); $i++) {
        expect($stages[$i]['count'])->toBeLessThanOrEqual($stages[$i - 1]['count']);
    }

    $admin->delete();
});

// ── GET /api/admin/reports/pipeline ──────────────────────────────────

test('pipeline report returns pending employer stats', function () {
    [$admin, $token] = adminToken();

    $resp = $this->withToken($token)->getJson('/api/admin/reports/pipeline')
        ->assertStatus(200)
        ->assertJsonStructure(['pending_count', 'avg_wait_days', 'estimated_lost_revenue', 'employers']);

    $admin->delete();
});

// Property 4: Estimated lost revenue calculation is exact
test('estimated lost revenue equals pending_count x avg_wait_days x rate', function () {
    [$admin, $token] = adminToken();

    // Seed exactly 2 pending employers created 2 days ago each
    $empUser1 = User::factory()->create(['roles' => ['employee']]);
    $empUser2 = User::factory()->create(['roles' => ['employee']]);

    $emp1 = Employer::create([
        'user_id'    => (string) $empUser1->_id,
        'status'     => 'pending',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);
    $emp2 = Employer::create([
        'user_id'    => (string) $empUser2->_id,
        'status'     => 'pending',
        'created_at' => now()->subDays(2),
        'updated_at' => now()->subDays(2),
    ]);

    $resp = $this->withToken($token)
        ->getJson('/api/admin/reports/pipeline?daily_revenue_per_employer=10')
        ->assertStatus(200);

    $data     = $resp->json();
    $p        = $data['pending_count'];
    $w        = $data['avg_wait_days'];
    $r        = $data['daily_revenue_per_employer'];
    $expected = round($p * $w * $r, 2);

    expect((float) $data['estimated_lost_revenue'])->toBe($expected);

    $emp1->delete(); $emp2->delete();
    $empUser1->delete(); $empUser2->delete();
    $admin->delete();
});

// ── GET /api/admin/reports/categories ────────────────────────────────

// Property 5: Top categories sort order matches sort_by parameter
test('categories sorted by applications descending by default', function () {
    [$admin, $token] = adminToken();
    $employer = User::factory()->employer()->create();

    // Create posts with different categories
    $postA = JobPost::create(['employer_id' => (string) $employer->_id, 'title' => 'A', 'is_active' => true, 'category' => 'Alpha']);
    $postB = JobPost::create(['employer_id' => (string) $employer->_id, 'title' => 'B', 'is_active' => true, 'category' => 'Beta']);
    $seeker = User::factory()->employee()->create();

    // 3 applications for Beta, 1 for Alpha
    Application::create(['user_id' => (string) $seeker->_id, 'job_post_id' => (string) $postB->_id, 'status' => 'pending']);
    Application::create(['user_id' => (string) $seeker->_id, 'job_post_id' => (string) $postB->_id, 'status' => 'pending']);
    Application::create(['user_id' => (string) $seeker->_id, 'job_post_id' => (string) $postB->_id, 'status' => 'pending']);
    Application::create(['user_id' => (string) $seeker->_id, 'job_post_id' => (string) $postA->_id, 'status' => 'pending']);

    $resp = $this->withToken($token)->getJson('/api/admin/reports/categories')
        ->assertStatus(200);

    $cats = collect($resp->json('categories'));
    // Verify descending order
    for ($i = 1; $i < $cats->count(); $i++) {
        expect($cats[$i]['application_count'])->toBeLessThanOrEqual($cats[$i - 1]['application_count']);
    }

    // Beta should appear before Alpha
    $names = $cats->pluck('category')->toArray();
    $betaIdx  = array_search('Beta', $names);
    $alphaIdx = array_search('Alpha', $names);
    if ($betaIdx !== false && $alphaIdx !== false) {
        expect($betaIdx)->toBeLessThan($alphaIdx);
    }

    JobPost::whereIn('_id', [(string) $postA->_id, (string) $postB->_id])->delete();
    Application::where('job_post_id', (string) $postA->_id)->orWhere('job_post_id', (string) $postB->_id)->delete();
    $employer->delete(); $seeker->delete(); $admin->delete();
});

test('categories sorted by posts when sort_by=posts', function () {
    [$admin, $token] = adminToken();

    $resp = $this->withToken($token)->getJson('/api/admin/reports/categories?sort_by=posts')
        ->assertStatus(200);

    $cats = collect($resp->json('categories'));
    for ($i = 1; $i < $cats->count(); $i++) {
        expect($cats[$i]['post_count'])->toBeLessThanOrEqual($cats[$i - 1]['post_count']);
    }

    $admin->delete();
});

test('categories limit parameter is respected', function () {
    [$admin, $token] = adminToken();

    $resp = $this->withToken($token)->getJson('/api/admin/reports/categories?limit=3')
        ->assertStatus(200);

    expect(count($resp->json('categories')))->toBeLessThanOrEqual(3);

    $admin->delete();
});
