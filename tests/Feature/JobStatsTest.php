<?php

// =============================================================================
// JobStatsTest
//
// Covers:
//   GET /api/jobs/stats/by-location
//   GET /api/jobs/stats/by-category
// =============================================================================

use App\Models\JobPost;
use App\Models\User;

// ── Helpers ──────────────────────────────────────────────────────────────────

function statsJob(string $employerId, array $overrides = []): JobPost
{
    return JobPost::create(array_merge([
        'title'                => 'Stats Test Job',
        'description'          => 'Test',
        'employer_id'          => $employerId,
        'communication_method' => 'by_forsa',
        'vacancies'            => 1,
        'job_type'             => 'full_time',
        'is_active'            => true,
    ], $overrides));
}

afterEach(function () {
    JobPost::where('title', 'Stats Test Job')->delete();
});

// =============================================================================
// GET /api/jobs/stats/by-location
// =============================================================================

test('stats by location requires no authentication', function () {
    $this->getJson('/api/jobs/stats/by-location')->assertStatus(200);
});

test('stats by location returns data array', function () {
    $res = $this->getJson('/api/jobs/stats/by-location')->assertStatus(200);

    expect($res->json())->toHaveKey('data');
    expect($res->json('data'))->toBeArray();
});

test('stats by location counts active jobs per city', function () {
    $employer = User::factory()->employer()->create();
    $id = (string) $employer->_id;

    statsJob($id, ['city' => 'Damascus']);
    statsJob($id, ['city' => 'Damascus']);
    statsJob($id, ['city' => 'Aleppo']);

    $data = collect($this->getJson('/api/jobs/stats/by-location')->json('data'));

    $damascus = $data->firstWhere('city', 'Damascus');
    $aleppo   = $data->firstWhere('city', 'Aleppo');

    expect($damascus['count'])->toBeGreaterThanOrEqual(2);
    expect($aleppo['count'])->toBeGreaterThanOrEqual(1);

    $employer->delete();
});

test('stats by location excludes inactive jobs', function () {
    $employer = User::factory()->employer()->create();
    $id = (string) $employer->_id;

    $activeCity   = 'StatsActiveCity_' . uniqid();
    $inactiveCity = 'StatsInactiveCity_' . uniqid();

    statsJob($id, ['city' => $activeCity,   'is_active' => true]);
    statsJob($id, ['city' => $inactiveCity, 'is_active' => false]);

    $data = collect($this->getJson('/api/jobs/stats/by-location')->json('data'));

    expect($data->firstWhere('city', $activeCity))->not->toBeNull();
    expect($data->firstWhere('city', $inactiveCity))->toBeNull();

    $employer->delete();
});

test('stats by location omits entries with null city', function () {
    $employer = User::factory()->employer()->create();

    statsJob((string) $employer->_id); // no city field

    $data = collect($this->getJson('/api/jobs/stats/by-location')->json('data'));

    $nullEntry = $data->first(fn ($item) => is_null($item['city'] ?? 'placeholder'));
    expect($nullEntry)->toBeNull();

    $employer->delete();
});

test('stats by location returns items sorted by count descending', function () {
    $employer = User::factory()->employer()->create();
    $id = (string) $employer->_id;

    $bigCity   = 'BigStatsCity_' . uniqid();
    $smallCity = 'SmallStatsCity_' . uniqid();

    statsJob($id, ['city' => $bigCity]);
    statsJob($id, ['city' => $bigCity]);
    statsJob($id, ['city' => $bigCity]);
    statsJob($id, ['city' => $smallCity]);

    $data = collect($this->getJson('/api/jobs/stats/by-location')->json('data'));

    $bigIndex   = $data->search(fn ($item) => $item['city'] === $bigCity);
    $smallIndex = $data->search(fn ($item) => $item['city'] === $smallCity);

    expect($bigIndex)->toBeLessThan($smallIndex);

    $employer->delete();
});

// =============================================================================
// GET /api/jobs/stats/by-category
// =============================================================================

test('stats by category requires no authentication', function () {
    $this->getJson('/api/jobs/stats/by-category')->assertStatus(200);
});

test('stats by category returns data array', function () {
    $res = $this->getJson('/api/jobs/stats/by-category')->assertStatus(200);

    expect($res->json())->toHaveKey('data');
    expect($res->json('data'))->toBeArray();
});

test('stats by category counts active jobs per category', function () {
    $employer = User::factory()->employer()->create();
    $id = (string) $employer->_id;

    statsJob($id, ['category' => 'Technology']);
    statsJob($id, ['category' => 'Technology']);
    statsJob($id, ['category' => 'Healthcare']);

    $data = collect($this->getJson('/api/jobs/stats/by-category')->json('data'));

    $tech      = $data->firstWhere('category', 'Technology');
    $health    = $data->firstWhere('category', 'Healthcare');

    expect($tech['count'])->toBeGreaterThanOrEqual(2);
    expect($health['count'])->toBeGreaterThanOrEqual(1);

    $employer->delete();
});

test('stats by category excludes inactive jobs', function () {
    $employer = User::factory()->employer()->create();
    $id = (string) $employer->_id;

    $activeCategory   = 'StatsCatActive_' . uniqid();
    $inactiveCategory = 'StatsCatInactive_' . uniqid();

    statsJob($id, ['category' => $activeCategory,   'is_active' => true]);
    statsJob($id, ['category' => $inactiveCategory, 'is_active' => false]);

    $data = collect($this->getJson('/api/jobs/stats/by-category')->json('data'));

    expect($data->firstWhere('category', $activeCategory))->not->toBeNull();
    expect($data->firstWhere('category', $inactiveCategory))->toBeNull();

    $employer->delete();
});

test('stats by category omits entries with null category', function () {
    $employer = User::factory()->employer()->create();

    statsJob((string) $employer->_id); // no category field

    $data = collect($this->getJson('/api/jobs/stats/by-category')->json('data'));

    $nullEntry = $data->first(fn ($item) => is_null($item['category'] ?? 'placeholder'));
    expect($nullEntry)->toBeNull();

    $employer->delete();
});

test('stats by category returns items sorted by count descending', function () {
    $employer = User::factory()->employer()->create();
    $id = (string) $employer->_id;

    $bigCat   = 'BigStatsCat_' . uniqid();
    $smallCat = 'SmallStatsCat_' . uniqid();

    statsJob($id, ['category' => $bigCat]);
    statsJob($id, ['category' => $bigCat]);
    statsJob($id, ['category' => $bigCat]);
    statsJob($id, ['category' => $smallCat]);

    $data = collect($this->getJson('/api/jobs/stats/by-category')->json('data'));

    $bigIndex   = $data->search(fn ($item) => $item['category'] === $bigCat);
    $smallIndex = $data->search(fn ($item) => $item['category'] === $smallCat);

    expect($bigIndex)->toBeLessThan($smallIndex);

    $employer->delete();
});
