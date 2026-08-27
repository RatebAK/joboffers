<?php

// =============================================================================
// CityLookupTest
//
// Mirrors CategoryLookupTest for the cities collection.
// Cities are advisory (freeform accepted on job posts/profiles),
// but the CRUD contract is identical to categories.
// =============================================================================

use App\Models\City;
use App\Models\User;

// ── Helpers ──────────────────────────────────────────────────────────────────

function cityAdmin(): array
{
    $user  = User::factory()->admin()->create();
    $token = auth('api')->login($user);
    return [$user, $token];
}

function cityEmployer(): array
{
    $user  = User::factory()->employer()->create();
    $token = auth('api')->login($user);
    return [$user, $token];
}

function cityEmployee(): array
{
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    return [$user, $token];
}

afterEach(function () {
    City::truncate();
});

// =============================================================================
// A. Public listing
// =============================================================================

test('GET /api/cities requires no authentication (P7)', function () {
    // Feature: lookup-tables, Property 7: public listing requires no authentication
    $this->getJson('/api/cities')->assertStatus(200);
});

test('GET /api/cities returns data array', function () {
    City::create(['name' => 'Damascus']);

    $res = $this->getJson('/api/cities')->assertStatus(200);

    expect($res->json('data'))->toBeArray();
    expect($res->json('data.0.name'))->toBe('Damascus');
});

test('GET /api/cities returns items sorted by name', function () {
    City::create(['name' => 'Latakia']);
    City::create(['name' => 'Aleppo']);
    City::create(['name' => 'Homs']);

    $names = collect($this->getJson('/api/cities')->json('data'))->pluck('name')->toArray();

    expect($names)->toBe(['Aleppo', 'Homs', 'Latakia']);
});

// =============================================================================
// B. Admin CRUD
// =============================================================================

test('admin can create a city', function () {
    [$admin, $token] = cityAdmin();

    $res = $this->withToken($token)
        ->postJson('/api/admin/cities', ['name' => 'Damascus'])
        ->assertStatus(201);

    expect($res->json('name'))->toBe('Damascus');

    $admin->delete();
});

test('admin can list cities', function () {
    [$admin, $token] = cityAdmin();
    City::create(['name' => 'Damascus']);

    $this->withToken($token)
        ->getJson('/api/admin/cities')
        ->assertStatus(200)
        ->assertJsonStructure(['data']);

    $admin->delete();
});

test('admin can update a city', function () {
    [$admin, $token] = cityAdmin();
    $city = City::create(['name' => 'OldCity']);

    $res = $this->withToken($token)
        ->putJson("/api/admin/cities/{$city->_id}", ['name' => 'NewCity'])
        ->assertStatus(200);

    expect($res->json('name'))->toBe('NewCity');

    $admin->delete();
});

test('admin can delete a city', function () {
    [$admin, $token] = cityAdmin();
    $city = City::create(['name' => 'ToDelete']);

    $this->withToken($token)
        ->deleteJson("/api/admin/cities/{$city->_id}")
        ->assertStatus(200)
        ->assertJsonPath('message', 'Deleted successfully.');

    expect(City::find($city->_id))->toBeNull();

    $admin->delete();
});

// =============================================================================
// C. Access control (P8)
// =============================================================================

test('unauthenticated POST to admin cities returns 401 (P8)', function () {
    // Feature: lookup-tables, Property 8: non-admin requests rejected
    $this->postJson('/api/admin/cities', ['name' => 'X'])->assertStatus(401);
});

test('employee POST to admin cities returns 403 (P8)', function () {
    [$user, $token] = cityEmployee();

    $this->withToken($token)
        ->postJson('/api/admin/cities', ['name' => 'X'])
        ->assertStatus(403);

    $user->delete();
});

test('employer POST to admin cities returns 403 (P8)', function () {
    [$user, $token] = cityEmployer();

    $this->withToken($token)
        ->postJson('/api/admin/cities', ['name' => 'X'])
        ->assertStatus(403);

    $user->delete();
});

// =============================================================================
// D. Validation
// =============================================================================

test('create city with empty name returns 422', function () {
    [$admin, $token] = cityAdmin();

    $this->withToken($token)
        ->postJson('/api/admin/cities', ['name' => ''])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);

    $admin->delete();
});

test('update city with unknown id returns 404', function () {
    [$admin, $token] = cityAdmin();

    $this->withToken($token)
        ->putJson('/api/admin/cities/000000000000000000000000', ['name' => 'X'])
        ->assertStatus(404);

    $admin->delete();
});

test('delete city with unknown id returns 404', function () {
    [$admin, $token] = cityAdmin();

    $this->withToken($token)
        ->deleteJson('/api/admin/cities/000000000000000000000000')
        ->assertStatus(404);

    $admin->delete();
});

// =============================================================================
// P1. Unique name enforcement
// =============================================================================

test('duplicate city name is rejected (P1)', function () {
    // Feature: lookup-tables, Property 1: unique name enforcement
    [$admin, $token] = cityAdmin();

    $this->withToken($token)->postJson('/api/admin/cities', ['name' => 'Damascus'])->assertStatus(201);
    $this->withToken($token)->postJson('/api/admin/cities', ['name' => 'Damascus'])->assertStatus(422);

    $admin->delete();
});

test('duplicate city name via PUT on different item is rejected (P1)', function () {
    // Feature: lookup-tables, Property 1: unique name enforcement
    [$admin, $token] = cityAdmin();

    City::create(['name' => 'Damascus']);
    $other = City::create(['name' => 'Aleppo']);

    $this->withToken($token)
        ->putJson("/api/admin/cities/{$other->_id}", ['name' => 'Damascus'])
        ->assertStatus(422);

    $admin->delete();
});

// =============================================================================
// P2. Create then retrieve round trip
// =============================================================================

test('created cities appear in GET listing (P2)', function () {
    // Feature: lookup-tables, Property 2: create then retrieve round trip
    [$admin, $token] = cityAdmin();

    $names = ['Damascus', 'Aleppo', 'Homs', 'Hama', 'Latakia'];

    foreach ($names as $name) {
        $this->withToken($token)
            ->postJson('/api/admin/cities', ['name' => $name])
            ->assertStatus(201);
    }

    $listed = collect($this->getJson('/api/cities')->json('data'))->pluck('name')->toArray();

    foreach ($names as $name) {
        expect($listed)->toContain($name);
    }

    $admin->delete();
});

// =============================================================================
// P3. Delete removes from list
// =============================================================================

test('deleted city no longer appears in GET listing (P3)', function () {
    // Feature: lookup-tables, Property 3: delete removes from list
    [$admin, $token] = cityAdmin();

    $city = City::create(['name' => 'Palmyra']);

    $this->withToken($token)
        ->deleteJson("/api/admin/cities/{$city->_id}")
        ->assertStatus(200);

    $ids = collect($this->getJson('/api/cities')->json('data'))->pluck('id')->toArray();
    expect($ids)->not->toContain((string) $city->_id);

    $admin->delete();
});

// =============================================================================
// P4. Update preserves identity, changes name
// =============================================================================

test('updated city keeps same id and shows new name in listing (P4)', function () {
    // Feature: lookup-tables, Property 4: update preserves identity
    [$admin, $token] = cityAdmin();

    $city = City::create(['name' => 'OldCity']);
    $originalId = (string) $city->_id;

    $res = $this->withToken($token)
        ->putJson("/api/admin/cities/{$city->_id}", ['name' => 'NewCity'])
        ->assertStatus(200);

    expect($res->json('id'))->toBe($originalId);
    expect($res->json('name'))->toBe('NewCity');

    $listed = collect($this->getJson('/api/cities')->json('data'))->pluck('name')->toArray();
    expect($listed)->toContain('NewCity');
    expect($listed)->not->toContain('OldCity');

    $admin->delete();
});
