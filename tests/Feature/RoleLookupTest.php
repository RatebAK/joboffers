<?php

// =============================================================================
// RoleLookupTest
//
// Mirrors CategoryLookupTest for the roles collection.
// Roles are advisory (freeform accepted on job posts/profiles).
// =============================================================================

use App\Models\Role;
use App\Models\User;

// ── Helpers ──────────────────────────────────────────────────────────────────

function roleAdmin(): array
{
    $user  = User::factory()->admin()->create();
    $token = auth('api')->login($user);
    return [$user, $token];
}

function roleEmployer(): array
{
    $user  = User::factory()->employer()->create();
    $token = auth('api')->login($user);
    return [$user, $token];
}

function roleEmployee(): array
{
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    return [$user, $token];
}

afterEach(function () {
    Role::truncate();
});

// =============================================================================
// A. Public listing
// =============================================================================

test('GET /api/roles requires no authentication (P7)', function () {
    // Feature: lookup-tables, Property 7: public listing requires no authentication
    $this->getJson('/api/roles')->assertStatus(200);
});

test('GET /api/roles returns data array', function () {
    Role::create(['name' => 'Software Engineer']);

    $res = $this->getJson('/api/roles')->assertStatus(200);

    expect($res->json('data'))->toBeArray();
    expect($res->json('data.0.name'))->toBe('Software Engineer');
});

test('GET /api/roles returns items sorted by name', function () {
    Role::create(['name' => 'Project Manager']);
    Role::create(['name' => 'Backend Developer']);
    Role::create(['name' => 'Data Analyst']);

    $names = collect($this->getJson('/api/roles')->json('data'))->pluck('name')->toArray();

    expect($names)->toBe(['Backend Developer', 'Data Analyst', 'Project Manager']);
});

// =============================================================================
// B. Admin CRUD
// =============================================================================

test('admin can create a role', function () {
    [$admin, $token] = roleAdmin();

    $res = $this->withToken($token)
        ->postJson('/api/admin/roles', ['name' => 'Software Engineer'])
        ->assertStatus(201);

    expect($res->json('name'))->toBe('Software Engineer');

    $admin->delete();
});

test('admin can list roles', function () {
    [$admin, $token] = roleAdmin();
    Role::create(['name' => 'DevOps Engineer']);

    $this->withToken($token)
        ->getJson('/api/admin/roles')
        ->assertStatus(200)
        ->assertJsonStructure(['data']);

    $admin->delete();
});

test('admin can update a role', function () {
    [$admin, $token] = roleAdmin();
    $role = Role::create(['name' => 'OldRole']);

    $res = $this->withToken($token)
        ->putJson("/api/admin/roles/{$role->_id}", ['name' => 'NewRole'])
        ->assertStatus(200);

    expect($res->json('name'))->toBe('NewRole');

    $admin->delete();
});

test('admin can delete a role', function () {
    [$admin, $token] = roleAdmin();
    $role = Role::create(['name' => 'ToDelete']);

    $this->withToken($token)
        ->deleteJson("/api/admin/roles/{$role->_id}")
        ->assertStatus(200)
        ->assertJsonPath('message', 'Deleted successfully.');

    expect(Role::find($role->_id))->toBeNull();

    $admin->delete();
});

// =============================================================================
// C. Access control (P8)
// =============================================================================

test('unauthenticated POST to admin roles returns 401 (P8)', function () {
    // Feature: lookup-tables, Property 8: non-admin requests rejected
    $this->postJson('/api/admin/roles', ['name' => 'X'])->assertStatus(401);
});

test('employee POST to admin roles returns 403 (P8)', function () {
    [$user, $token] = roleEmployee();

    $this->withToken($token)
        ->postJson('/api/admin/roles', ['name' => 'X'])
        ->assertStatus(403);

    $user->delete();
});

test('employer POST to admin roles returns 403 (P8)', function () {
    [$user, $token] = roleEmployer();

    $this->withToken($token)
        ->postJson('/api/admin/roles', ['name' => 'X'])
        ->assertStatus(403);

    $user->delete();
});

// =============================================================================
// D. Validation
// =============================================================================

test('create role with empty name returns 422', function () {
    [$admin, $token] = roleAdmin();

    $this->withToken($token)
        ->postJson('/api/admin/roles', ['name' => ''])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['name']]);

    $admin->delete();
});

test('update role with unknown id returns 404', function () {
    [$admin, $token] = roleAdmin();

    $this->withToken($token)
        ->putJson('/api/admin/roles/000000000000000000000000', ['name' => 'X'])
        ->assertStatus(404);

    $admin->delete();
});

test('delete role with unknown id returns 404', function () {
    [$admin, $token] = roleAdmin();

    $this->withToken($token)
        ->deleteJson('/api/admin/roles/000000000000000000000000')
        ->assertStatus(404);

    $admin->delete();
});

// =============================================================================
// P1. Unique name enforcement
// =============================================================================

test('duplicate role name is rejected (P1)', function () {
    // Feature: lookup-tables, Property 1: unique name enforcement
    [$admin, $token] = roleAdmin();

    $this->withToken($token)->postJson('/api/admin/roles', ['name' => 'DevOps Engineer'])->assertStatus(201);
    $this->withToken($token)->postJson('/api/admin/roles', ['name' => 'DevOps Engineer'])->assertStatus(422);

    $admin->delete();
});

test('duplicate role name via PUT on different item is rejected (P1)', function () {
    // Feature: lookup-tables, Property 1: unique name enforcement
    [$admin, $token] = roleAdmin();

    Role::create(['name' => 'DevOps Engineer']);
    $other = Role::create(['name' => 'Backend Developer']);

    $this->withToken($token)
        ->putJson("/api/admin/roles/{$other->_id}", ['name' => 'DevOps Engineer'])
        ->assertStatus(422);

    $admin->delete();
});

// =============================================================================
// P2. Create then retrieve round trip
// =============================================================================

test('created roles appear in GET listing (P2)', function () {
    // Feature: lookup-tables, Property 2: create then retrieve round trip
    [$admin, $token] = roleAdmin();

    $names = ['Software Engineer', 'DevOps Engineer', 'Data Analyst', 'Product Manager', 'UX Designer'];

    foreach ($names as $name) {
        $this->withToken($token)
            ->postJson('/api/admin/roles', ['name' => $name])
            ->assertStatus(201);
    }

    $listed = collect($this->getJson('/api/roles')->json('data'))->pluck('name')->toArray();

    foreach ($names as $name) {
        expect($listed)->toContain($name);
    }

    $admin->delete();
});

// =============================================================================
// P3. Delete removes from list
// =============================================================================

test('deleted role no longer appears in GET listing (P3)', function () {
    // Feature: lookup-tables, Property 3: delete removes from list
    [$admin, $token] = roleAdmin();

    $role = Role::create(['name' => 'ObsoleteRole']);

    $this->withToken($token)
        ->deleteJson("/api/admin/roles/{$role->_id}")
        ->assertStatus(200);

    $ids = collect($this->getJson('/api/roles')->json('data'))->pluck('id')->toArray();
    expect($ids)->not->toContain((string) $role->_id);

    $admin->delete();
});

// =============================================================================
// P4. Update preserves identity, changes name
// =============================================================================

test('updated role keeps same id and shows new name in listing (P4)', function () {
    // Feature: lookup-tables, Property 4: update preserves identity
    [$admin, $token] = roleAdmin();

    $role = Role::create(['name' => 'OldRole']);
    $originalId = (string) $role->_id;

    $res = $this->withToken($token)
        ->putJson("/api/admin/roles/{$role->_id}", ['name' => 'NewRole'])
        ->assertStatus(200);

    expect($res->json('id'))->toBe($originalId);
    expect($res->json('name'))->toBe('NewRole');

    $listed = collect($this->getJson('/api/roles')->json('data'))->pluck('name')->toArray();
    expect($listed)->toContain('NewRole');
    expect($listed)->not->toContain('OldRole');

    $admin->delete();
});
