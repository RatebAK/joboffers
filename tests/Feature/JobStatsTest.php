<?php

// Covers the public job stats endpoints:
//   GET /api/jobs/stats/by-location
//   GET /api/jobs/stats/by-category

beforeEach(function () {
    $this->employer = createUser('employer');
});

// ── GET /api/jobs/stats/by-location ────────────────────────────────

test('stats by location requires no authentication', function () {
    $this->getJson('/api/jobs/stats/by-location')->assertOk();
});

test('stats by location returns data array', function () {
    $res = $this->getJson('/api/jobs/stats/by-location')->assertOk();

    expect($res->json())->toHaveKey('data');
    expect($res->json('data'))->toBeArray();
});

test('stats by location counts active jobs per city', function () {
    createJob($this->employer, ['city' => 'Damascus']);
    createJob($this->employer, ['city' => 'Damascus']);
    createJob($this->employer, ['city' => 'Aleppo']);

    $data = collect($this->getJson('/api/jobs/stats/by-location')->assertOk()->json('data'));

    expect($data->firstWhere('city', 'Damascus')['count'])->toBeGreaterThanOrEqual(2);
    expect($data->firstWhere('city', 'Aleppo')['count'])->toBeGreaterThanOrEqual(1);
});

test('stats by location excludes inactive jobs', function () {
    $activeCity   = 'StatsActiveCity_'.uniqid();
    $inactiveCity = 'StatsInactiveCity_'.uniqid();

    createJob($this->employer, ['city' => $activeCity, 'is_active' => true]);
    createJob($this->employer, ['city' => $inactiveCity, 'is_active' => false]);

    $data = collect($this->getJson('/api/jobs/stats/by-location')->assertOk()->json('data'));

    expect($data->firstWhere('city', $activeCity))->not->toBeNull();
    expect($data->firstWhere('city', $inactiveCity))->toBeNull();
});

test('stats by location omits entries with null city', function () {
    createJob($this->employer, ['city' => null]);

    $data = collect($this->getJson('/api/jobs/stats/by-location')->assertOk()->json('data'));

    $nullEntry = $data->first(fn ($item) => is_null($item['city'] ?? 'placeholder'));
    expect($nullEntry)->toBeNull();
});

test('stats by location returns items sorted by count descending', function () {
    $bigCity   = 'BigStatsCity_'.uniqid();
    $smallCity = 'SmallStatsCity_'.uniqid();

    createJob($this->employer, ['city' => $bigCity]);
    createJob($this->employer, ['city' => $bigCity]);
    createJob($this->employer, ['city' => $bigCity]);
    createJob($this->employer, ['city' => $smallCity]);

    $data = collect($this->getJson('/api/jobs/stats/by-location')->assertOk()->json('data'));

    $bigIndex   = $data->search(fn ($item) => $item['city'] === $bigCity);
    $smallIndex = $data->search(fn ($item) => $item['city'] === $smallCity);

    expect($bigIndex)->toBeLessThan($smallIndex);
});

// ── GET /api/jobs/stats/by-category ────────────────────────────────

test('stats by category requires no authentication', function () {
    $this->getJson('/api/jobs/stats/by-category')->assertOk();
});

test('stats by category returns data array', function () {
    $res = $this->getJson('/api/jobs/stats/by-category')->assertOk();

    expect($res->json())->toHaveKey('data');
    expect($res->json('data'))->toBeArray();
});

test('stats by category counts active jobs per category', function () {
    createJob($this->employer, ['category' => 'Technology']);
    createJob($this->employer, ['category' => 'Technology']);
    createJob($this->employer, ['category' => 'Healthcare']);

    $data = collect($this->getJson('/api/jobs/stats/by-category')->assertOk()->json('data'));

    expect($data->firstWhere('category', 'Technology')['count'])->toBeGreaterThanOrEqual(2);
    expect($data->firstWhere('category', 'Healthcare')['count'])->toBeGreaterThanOrEqual(1);
});

test('stats by category excludes inactive jobs', function () {
    $activeCategory   = 'StatsCatActive_'.uniqid();
    $inactiveCategory = 'StatsCatInactive_'.uniqid();

    createJob($this->employer, ['category' => $activeCategory, 'is_active' => true]);
    createJob($this->employer, ['category' => $inactiveCategory, 'is_active' => false]);

    $data = collect($this->getJson('/api/jobs/stats/by-category')->assertOk()->json('data'));

    expect($data->firstWhere('category', $activeCategory))->not->toBeNull();
    expect($data->firstWhere('category', $inactiveCategory))->toBeNull();
});

test('stats by category omits entries with null category', function () {
    createJob($this->employer, ['category' => null]);

    $data = collect($this->getJson('/api/jobs/stats/by-category')->assertOk()->json('data'));

    $nullEntry = $data->first(fn ($item) => is_null($item['category'] ?? 'placeholder'));
    expect($nullEntry)->toBeNull();
});

test('stats by category returns items sorted by count descending', function () {
    $bigCat   = 'BigStatsCat_'.uniqid();
    $smallCat = 'SmallStatsCat_'.uniqid();

    createJob($this->employer, ['category' => $bigCat]);
    createJob($this->employer, ['category' => $bigCat]);
    createJob($this->employer, ['category' => $bigCat]);
    createJob($this->employer, ['category' => $smallCat]);

    $data = collect($this->getJson('/api/jobs/stats/by-category')->assertOk()->json('data'));

    $bigIndex   = $data->search(fn ($item) => $item['category'] === $bigCat);
    $smallIndex = $data->search(fn ($item) => $item['category'] === $smallCat);

    expect($bigIndex)->toBeLessThan($smallIndex);
});
