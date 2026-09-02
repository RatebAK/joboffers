<?php

// =============================================================================
// EducationLookupTest
//
// Covers the Universities, Faculties, and Majors lookup tables. All three share
// the same contract as the existing category/city/role lookups:
//   - Public GET listing (no auth), sorted by name
//   - Admin-only CRUD (create / update / delete)
//   - Unique name enforcement
//
// The contract is written once and driven against all three collections via a
// dataset, so adding a fourth lookup later is a one-line change.
// =============================================================================

use App\Models\Faculty;
use App\Models\Major;
use App\Models\University;

/**
 * Each row: [public route segment, admin route segment, model class].
 * Public and admin segments are identical here, but kept explicit for clarity.
 */
dataset('lookups', [
    'universities' => ['universities', University::class],
    'faculties'    => ['faculties', Faculty::class],
    'majors'       => ['majors', Major::class],
]);

// ── Public listing ───────────────────────────────────────────────────────────

test('anyone can list without authentication', function (string $segment, string $model) {
    $model::create(['name' => 'Damascus University']);

    $this->getJson("/api/{$segment}")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Damascus University');
})->with('lookups');

test('listing is sorted by name', function (string $segment, string $model) {
    $model::create(['name' => 'Tishreen']);
    $model::create(['name' => 'Aleppo']);
    $model::create(['name' => 'Homs']);

    $names = collect($this->getJson("/api/{$segment}")->json('data'))->pluck('name');

    expect($names->all())->toBe(['Aleppo', 'Homs', 'Tishreen']);
})->with('lookups');

// ── Admin CRUD ─────────────────────────────────────────────────────────────

test('admin can create an item', function (string $segment, string $model) {
    $this->withToken(tokenFor('admin'))
        ->postJson("/api/admin/{$segment}", ['name' => 'Faculty of Engineering'])
        ->assertCreated()
        ->assertJsonPath('name', 'Faculty of Engineering');

    expect($model::where('name', 'Faculty of Engineering')->exists())->toBeTrue();
})->with('lookups');

test('admin can update an item, preserving its id', function (string $segment, string $model) {
    $item = $model::create(['name' => 'Old Name']);

    $this->withToken(tokenFor('admin'))
        ->putJson("/api/admin/{$segment}/{$item->_id}", ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('id', (string) $item->_id)
        ->assertJsonPath('name', 'New Name');
})->with('lookups');

test('admin can delete an item', function (string $segment, string $model) {
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
        $this->withToken($token)
            ->postJson("/api/admin/{$segment}", ['name' => $name])
            ->assertCreated();
    }

    $listed = collect($this->getJson("/api/{$segment}")->json('data'))->pluck('name');

    expect($listed->all())->toBe(['Alpha', 'Beta', 'Gamma']);
})->with('lookups');

// ── Access control ─────────────────────────────────────────────────────────

test('guests cannot create items', function (string $segment) {
    $this->postJson("/api/admin/{$segment}", ['name' => 'X'])->assertUnauthorized();
})->with('lookups');

test('employees cannot create items', function (string $segment) {
    $this->withToken(tokenFor('employee'))
        ->postJson("/api/admin/{$segment}", ['name' => 'X'])
        ->assertForbidden();
})->with('lookups');

test('employers cannot create items', function (string $segment) {
    $this->withToken(tokenFor('employer'))
        ->postJson("/api/admin/{$segment}", ['name' => 'X'])
        ->assertForbidden();
})->with('lookups');

// ── Validation ───────────────────────────────────────────────────────────

test('creating without a name fails validation', function (string $segment) {
    $this->withToken(tokenFor('admin'))
        ->postJson("/api/admin/{$segment}", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');
})->with('lookups');

test('duplicate names are rejected', function (string $segment) {
    $token = tokenFor('admin');

    $this->withToken($token)->postJson("/api/admin/{$segment}", ['name' => 'Cairo University'])->assertCreated();
    $this->withToken($token)->postJson("/api/admin/{$segment}", ['name' => 'Cairo University'])->assertStatus(422);
})->with('lookups');

test('updating an unknown id returns 404', function (string $segment) {
    $this->withToken(tokenFor('admin'))
        ->putJson("/api/admin/{$segment}/000000000000000000000000", ['name' => 'X'])
        ->assertNotFound();
})->with('lookups');
