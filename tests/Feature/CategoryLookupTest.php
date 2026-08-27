<?php

// =============================================================================
// CategoryLookupTest
//
// Covers:
//   A. Public listing — no auth required
//   B. Admin CRUD — create, read, update, delete
//   C. Access control — unauthenticated and non-admin rejected
//   D. Validation — duplicate name, empty name, 404 on unknown id
//   P1. Unique name enforcement (property test)
//   P2. Create then retrieve round trip (property test)
//   P3. Delete removes from list (property test)
//   P4. Update preserves identity (property test)
//   P7. Public listing requires no auth (property test)
//   P8. Non-admin rejected (property test)
// =============================================================================

use App\Models\Category;
use App\Models\User;

// ── Helpers ──────────────────────────────────────────────────────────────────

function catAdmin(): array
{
    $user  = User::factory()->admin()->create();
    $token = auth('api')->login($user);
    return [$user, $token];
}

function catEmployer(): array
{
    $user  = User::factory()->employer()->create();
    $token = auth('api')->login($user);
    return [$user, $token];
}

function catEmployee(): array
{
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    return [$user, $token];
}

afterEach(function () {
    Category::truncate();
});

// =============================================================================
// A. Public listing
// =============================================================================

// P7 — public listing requires no authentication
test('GET /api/categories requires no authentication', function () {
    // Feature: lookup-tables, Property 7: public listing requires no authentication
    $this->getJson('/api/categories')->assertStatus(200);
});

test('GET /api/categories returns data array', function () {
    Category::create(['name' => 'Technology']);

    $res = $this->getJson('/api/categories')->assertStatus(200);

    expect($res->json('data'))->toBeArray();
    expect($res->json('data.0.name'))->toBe('Technology');
});

test('GET /api/categories returns items sorted by name', function () {
    Category::create(['name' => 'Technology']);
    Category::create(['name' => 'Design']);
    Category::create(['name' => 'Marketing']);

    $names = collect($this->getJson('/api/categories')->json('data'))->pluck('name')->toArray();

    expect($names)->toBe(['Design', 'Marketing', 'Technology']);
});

// =============================================================================
// B. Admin CRUD
// =============================================================================

test('admin can create a category', function () {
    [$admin, $token] = catAdmin();

    $res = $this->withToken($token)
        ->postJson('/api/admin/categories', ['name' => 'Technology'])
        ->assertStatus(201);

    expect($res->json('name'))->toBe('Technology');
    expect(Category::where('name', 'Technology')->exists())->toBeTrue();

    $admin->delete();
});

test('admin can list categories', function () {
    [$admin, $token] = catAdmin();
    Category::create(['name' => 'Technology']);

    $res = $this->withToken($token)
        ->getJson('/api/admin/categories')
        ->assertStatus(200);

    expect($res->json('data'))->toBeArray();

    $admin->delete();
});

test('admin can update a category', function () {
    [$admin, $token] = catAdmin();
    $cat = Category::create(['name' => 'OldName']);

    $res = $this->withToken($token)
        ->putJson("/api/admin/categories/{$cat->_id}", ['name' => 'NewName'])
        ->assertStatus(200);

    expect($res->json('name'))->toBe('NewName');
    expect(Category::where('name', 'NewName')->exists())->toBeTrue();

    $admin->delete();
});

test('admin can delete a category', function () {
    [$admin, $token] = catAdmin();
    $cat = Category::create(['name' => 'ToDelete']);

    $this->withToken($token)
        ->deleteJson("/api/admin/categories/{$cat->_id}")
        ->assertStatus(200)
        ->assertJsonPath('message', 'Deleted successfully.');

    expect(Category::find($cat->_id))->toBeNull();

    $admin->delete();
});

// =============================================================================
// C. Access control
// =============================================================================

// P8 — non-admin requests rejected
test('unauthenticated client gets 401 on admin POST', function () {
    // Feature: lookup-tables, Property 8: non-admin requests rejected
    $this->postJson('/api/admin/categories', ['name' => 'X'])->assertStatus(401);
});

test('employee gets 403 on admin POST', function () {
    [$user, $token] = catEmployee();

    $this->withToken($token)
        ->postJson('/api/admin/categories', ['name' => 'X'])
        ->assertStatus(403);

    $user->delete();
});

test('employer gets 403 on admin POST', function () {
    [$user, $token] = catEmployer();

    $this->withToken($token)
        ->postJson('/api/admin/categories', ['name' => 'X'])
        ->assertStatus(403);

    $user->delete();
});

test('unauthenticated client gets 401 on admin PUT', function () {
    $cat = Category::create(['name' => 'Existing']);

    $this->putJson("/api/admin/categories/{$cat->_id}", ['name' => 'New'])->assertStatus(401);
});

test('unauthenticated client gets 401 on admin DELETE', function () {
    $cat = Category::create(['name' => 'Existing']);

    $this->deleteJson("/api/admin/categories/{$cat->_id}")->assertStatus(401);
});

// =============================================================================
// D. Validation
// =============================================================================

test('create with empty name returns 422', function () {
    [$admin, $token] = catAdmin();

    $this->withToken($token)
        ->postJson('/api/admin/categories', ['name' => ''])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);

    $admin->delete();
});

test('create with missing name returns 422', function () {
    [$admin, $token] = catAdmin();

    $this->withToken($token)
        ->postJson('/api/admin/categories', [])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);

    $admin->delete();
});

test('update with unknown id returns 404', function () {
    [$admin, $token] = catAdmin();

    $this->withToken($token)
        ->putJson('/api/admin/categories/000000000000000000000000', ['name' => 'X'])
        ->assertStatus(404);

    $admin->delete();
});

test('delete with unknown id returns 404', function () {
    [$admin, $token] = catAdmin();

    $this->withToken($token)
        ->deleteJson('/api/admin/categories/000000000000000000000000')
        ->assertStatus(404);

    $admin->delete();
});

// =============================================================================
// P1. Unique name enforcement
// =============================================================================

test('duplicate category name is rejected (P1)', function () {
    // Feature: lookup-tables, Property 1: unique name enforcement
    [$admin, $token] = catAdmin();

    $this->withToken($token)
        ->postJson('/api/admin/categories', ['name' => 'Technology'])
        ->assertStatus(201);

    // Same name — exact
    $this->withToken($token)
        ->postJson('/api/admin/categories', ['name' => 'Technology'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);

    $admin->delete();
});

test('duplicate category name via PUT on different item is rejected (P1)', function () {
    // Feature: lookup-tables, Property 1: unique name enforcement
    [$admin, $token] = catAdmin();

    Category::create(['name' => 'Technology']);
    $other = Category::create(['name' => 'Design']);

    $this->withToken($token)
        ->putJson("/api/admin/categories/{$other->_id}", ['name' => 'Technology'])
        ->assertStatus(422);

    $admin->delete();
});

test('updating category to its own name is allowed (P1 — not a cross-item duplicate)', function () {
    // Feature: lookup-tables, Property 1: unique name enforcement
    [$admin, $token] = catAdmin();

    $cat = Category::create(['name' => 'Technology']);

    $this->withToken($token)
        ->putJson("/api/admin/categories/{$cat->_id}", ['name' => 'Technology'])
        ->assertStatus(200);

    $admin->delete();
});

// =============================================================================
// P2. Create then retrieve round trip
// =============================================================================

test('created categories appear in GET listing (P2)', function () {
    // Feature: lookup-tables, Property 2: create then retrieve round trip
    [$admin, $token] = catAdmin();

    $names = ['Alpha', 'Beta', 'Gamma', 'Delta', 'Epsilon'];

    foreach ($names as $name) {
        $this->withToken($token)
            ->postJson('/api/admin/categories', ['name' => $name])
            ->assertStatus(201);
    }

    $listed = collect($this->getJson('/api/categories')->json('data'))->pluck('name')->toArray();

    foreach ($names as $name) {
        expect($listed)->toContain($name);
    }

    $admin->delete();
});

// =============================================================================
// P3. Delete removes from list
// =============================================================================

test('deleted category no longer appears in GET listing (P3)', function () {
    // Feature: lookup-tables, Property 3: delete removes from list
    [$admin, $token] = catAdmin();

    $cat = Category::create(['name' => 'ToRemove']);

    $this->withToken($token)
        ->deleteJson("/api/admin/categories/{$cat->_id}")
        ->assertStatus(200);

    $ids = collect($this->getJson('/api/categories')->json('data'))->pluck('id')->toArray();
    expect($ids)->not->toContain((string) $cat->_id);

    $admin->delete();
});

// =============================================================================
// P4. Update preserves identity, changes name
// =============================================================================

test('updated category keeps same id and shows new name in listing (P4)', function () {
    // Feature: lookup-tables, Property 4: update preserves identity
    [$admin, $token] = catAdmin();

    $cat = Category::create(['name' => 'OldName']);
    $originalId = (string) $cat->_id;

    $res = $this->withToken($token)
        ->putJson("/api/admin/categories/{$cat->_id}", ['name' => 'NewName'])
        ->assertStatus(200);

    expect($res->json('id'))->toBe($originalId);
    expect($res->json('name'))->toBe('NewName');

    $listed = collect($this->getJson('/api/categories')->json('data'))->pluck('name')->toArray();
    expect($listed)->toContain('NewName');
    expect($listed)->not->toContain('OldName');

    $admin->delete();
});
