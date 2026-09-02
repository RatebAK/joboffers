<?php

// =============================================================================
// LookupTableTest
//
// The categories, cities, and roles lookups share one contract:
//   - Public GET listing (no auth), sorted by name
//   - Admin-only CRUD with unique-name enforcement
//
// The contract is written once and driven against all three collections via a
// dataset. (Universities/faculties/majors are covered by EducationLookupTest,
// which uses the same shape.)
// =============================================================================

use App\Models\Category;
use App\Models\City;
use App\Models\Role;

dataset('lookups', [
    'categories' => ['categories', Category::class],
    'cities'     => ['cities', City::class],
    'roles'      => ['roles', Role::class],
]);

// ── Public listing ───────────────────────────────────────────────────────

test('anyone can list a lookup without authentication', function (string $segment, string $model) {
    $model::create(['name' => 'Alpha']);

    $this->getJson("/api/{$segment}")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Alpha');
})->with('lookups');

test('a lookup listing is sorted by name', function (string $segment, string $model) {
    $model::create(['name' => 'Gamma']);
    $model::create(['name' => 'Alpha']);
    $model::create(['name' => 'Beta']);

    $names = collect($this->getJson("/api/{$segment}")->json('data'))->pluck('name');

    expect($names->all())->toBe(['Alpha', 'Beta', 'Gamma']);
})->with('lookups');

// ── Admin CRUD ─────────────────────────────────────────────────────────────

test('an admin can create a lookup item', function (string $segment, string $model) {
    $this->withToken(tokenFor('admin'))
        ->postJson("/api/admin/{$segment}", ['name' => 'Engineering'])
        ->assertCreated()
        ->assertJsonPath('name', 'Engineering');

    expect($model::where('name', 'Engineering')->exists())->toBeTrue();
})->with('lookups');

test('an admin can update a lookup item, preserving its id', function (string $segment, string $model) {
    $item = $model::create(['name' => 'Old Name']);

    $this->withToken(tokenFor('admin'))
        ->putJson("/api/admin/{$segment}/{$item->_id}", ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('id', (string) $item->_id)
        ->assertJsonPath('name', 'New Name');
})->with('lookups');

test('an admin can delete a lookup item', function (string $segment, string $model) {
    $item = $model::create(['name' => 'To Delete']);

    $this->withToken(tokenFor('admin'))
        ->deleteJson("/api/admin/{$segment}/{$item->_id}")
        ->assertOk()
        ->assertJsonPath('message', 'Deleted successfully.');

    expect($model::find($item->_id))->toBeNull();
})->with('lookups');

test('admin-created items appear in the public listing', function (string $segment, string $model) {
    $token = tokenFor('admin');

    foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
        $this->withToken($token)->postJson("/api/admin/{$segment}", ['name' => $name])->assertCreated();
    }

    $listed = collect($this->getJson("/api/{$segment}")->json('data'))->pluck('name');

    expect($listed->all())->toBe(['Alpha', 'Beta', 'Gamma']);
})->with('lookups');

// ── Access control ─────────────────────────────────────────────────────────

test('guests cannot create lookup items', function (string $segment) {
    $this->postJson("/api/admin/{$segment}", ['name' => 'X'])->assertUnauthorized();
})->with('lookups');

test('employees cannot create lookup items', function (string $segment) {
    $this->withToken(tokenFor('employee'))
        ->postJson("/api/admin/{$segment}", ['name' => 'X'])
        ->assertForbidden();
})->with('lookups');

test('employers cannot create lookup items', function (string $segment) {
    $this->withToken(tokenFor('employer'))
        ->postJson("/api/admin/{$segment}", ['name' => 'X'])
        ->assertForbidden();
})->with('lookups');

// ── Validation ───────────────────────────────────────────────────────────

test('creating a lookup item without a name fails validation', function (string $segment) {
    $this->withToken(tokenFor('admin'))
        ->postJson("/api/admin/{$segment}", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
})->with('lookups');

test('duplicate lookup names are rejected on create', function (string $segment) {
    $token = tokenFor('admin');

    $this->withToken($token)->postJson("/api/admin/{$segment}", ['name' => 'Engineering'])->assertCreated();
    $this->withToken($token)->postJson("/api/admin/{$segment}", ['name' => 'Engineering'])->assertStatus(422);
})->with('lookups');

test('duplicate lookup names are rejected when updating another item', function (string $segment, string $model) {
    $token = tokenFor('admin');
    $model::create(['name' => 'Engineering']);
    $other = $model::create(['name' => 'Design']);

    $this->withToken($token)
        ->putJson("/api/admin/{$segment}/{$other->_id}", ['name' => 'Engineering'])
        ->assertStatus(422);
})->with('lookups');

test('updating a lookup item to its own name is allowed', function (string $segment, string $model) {
    $item = $model::create(['name' => 'Engineering']);

    $this->withToken(tokenFor('admin'))
        ->putJson("/api/admin/{$segment}/{$item->_id}", ['name' => 'Engineering'])
        ->assertOk();
})->with('lookups');

test('updating a non-existent lookup item returns 404', function (string $segment) {
    $this->withToken(tokenFor('admin'))
        ->putJson("/api/admin/{$segment}/000000000000000000000000", ['name' => 'X'])
        ->assertNotFound();
})->with('lookups');

test('deleting a non-existent lookup item returns 404', function (string $segment) {
    $this->withToken(tokenFor('admin'))
        ->deleteJson("/api/admin/{$segment}/000000000000000000000000")
        ->assertNotFound();
})->with('lookups');
