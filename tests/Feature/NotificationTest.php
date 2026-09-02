<?php

// Covers notification listing, scoping, unread count, and mark-read behaviour
// (admin-business-intelligence properties 11 & 12).

use App\Models\Notification;
use App\Models\User;

// Seed a user with a number of unread (and optionally read) notifications.
// Kept local: there's no shared Notification builder.
function seedNotifications(User $user, int $unread, int $read = 0): void
{
    for ($i = 1; $i <= $unread; $i++) {
        Notification::create([
            'user_id' => (string) $user->_id,
            'type'    => 'broadcast',
            'message' => "Unread notification {$i}",
            'read_at' => null,
        ]);
    }

    for ($i = 1; $i <= $read; $i++) {
        $readTime = new \MongoDB\BSON\UTCDateTime(now()->getTimestampMs());
        Notification::query()->insert([
            'user_id'    => (string) $user->_id,
            'type'       => 'broadcast',
            'message'    => "Read notification {$i}",
            'read_at'    => $readTime,
            'created_at' => $readTime,
            'updated_at' => $readTime,
        ]);
    }
}

// ── GET /api/notifications ─────────────────────────────────────────

test('authenticated user can list their notifications', function () {
    [$user, $token] = userWithToken('employee');
    seedNotifications($user, 3);

    $this->withToken($token)->getJson('/api/notifications')
        ->assertOk()
        ->assertJsonStructure(['data', 'current_page', 'per_page', 'total', 'total_pages']);
});

test('notifications are ordered newest first', function () {
    [$user, $token] = userWithToken('employee');
    seedNotifications($user, 3);

    $dates = collect($this->withToken($token)->getJson('/api/notifications')->assertOk()->json('data'))
        ->pluck('created_at')->toArray();

    for ($i = 1; $i < count($dates); $i++) {
        expect($dates[$i])->toBeLessThanOrEqual($dates[$i - 1]);
    }
});

test('notifications are scoped to the authenticated user', function () {
    [$user1, $token1] = userWithToken('employee');
    [$user2]          = userWithToken('employee');

    seedNotifications($user1, 2);
    seedNotifications($user2, 5);

    expect($this->withToken($token1)->getJson('/api/notifications')->assertOk()->json('total'))->toBe(2);
});

test('unauthenticated user cannot list notifications', function () {
    $this->getJson('/api/notifications')->assertUnauthorized();
});

// ── GET /api/notifications/unread-count ────────────────────────────

test('unread count matches DB count of notifications with null read_at', function () {
    [$user, $token] = userWithToken('employee');
    seedNotifications($user, unread: 4, read: 2);

    $dbCount  = Notification::where('user_id', (string) $user->_id)->whereNull('read_at')->count();
    $apiCount = $this->withToken($token)->getJson('/api/notifications/unread-count')
        ->assertOk()->json('unread_count');

    expect($apiCount)->toBe($dbCount)->toBe(4);
});

test('unread count is 0 when all notifications are read', function () {
    [$user, $token] = userWithToken('employee');
    seedNotifications($user, unread: 0, read: 3);

    $this->withToken($token)->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJson(['unread_count' => 0]);
});

test('unread count is 0 when user has no notifications', function () {
    $this->withToken(tokenFor('employee'))->getJson('/api/notifications/unread-count')
        ->assertOk()
        ->assertJson(['unread_count' => 0]);
});

// ── POST /api/notifications/{id}/read ──────────────────────────────

test('user can mark a single notification as read', function () {
    [$user, $token] = userWithToken('employee');
    $notif = Notification::create([
        'user_id' => (string) $user->_id,
        'type'    => 'broadcast',
        'message' => 'Hello',
        'read_at' => null,
    ]);

    $resp = $this->withToken($token)
        ->postJson("/api/notifications/{$notif->_id}/read")
        ->assertOk();

    expect($resp->json('read_at'))->not->toBeNull();
    expect(Notification::find($notif->_id)->read_at)->not->toBeNull();
});

test('marking an already-read notification is idempotent', function () {
    [$user, $token] = userWithToken('employee');
    $notif = Notification::create([
        'user_id' => (string) $user->_id,
        'type'    => 'broadcast',
        'message' => 'Hello',
        'read_at' => now()->subMinutes(5),
    ]);

    $this->withToken($token)
        ->postJson("/api/notifications/{$notif->_id}/read")
        ->assertOk();
});

test('user cannot mark another users notification as read', function () {
    [$user2] = userWithToken('employee');
    $notif = Notification::create([
        'user_id' => (string) $user2->_id,
        'type'    => 'broadcast',
        'message' => 'For user2',
        'read_at' => null,
    ]);

    $this->withToken(tokenFor('employee'))
        ->postJson("/api/notifications/{$notif->_id}/read")
        ->assertNotFound();
});

// ── POST /api/notifications/read-all ───────────────────────────────

test('mark-all-read sets unread count to zero', function () {
    [$user, $token] = userWithToken('employee');
    seedNotifications($user, unread: 5, read: 2);

    $this->withToken($token)->postJson('/api/notifications/read-all')->assertOk();

    expect(Notification::where('user_id', (string) $user->_id)->whereNull('read_at')->count())->toBe(0);

    $apiCount = $this->withToken($token)->getJson('/api/notifications/unread-count')->json('unread_count');
    expect($apiCount)->toBe(0);
});

test('mark-all-read works when there are no unread notifications', function () {
    [$user, $token] = userWithToken('employee');
    seedNotifications($user, unread: 0, read: 3);

    $this->withToken($token)->postJson('/api/notifications/read-all')
        ->assertOk()
        ->assertJson(['updated' => 0]);
});

test('mark-all-read only affects the authenticated users notifications', function () {
    [$user1, $token1] = userWithToken('employee');
    [$user2]          = userWithToken('employee');

    seedNotifications($user1, 3);
    seedNotifications($user2, 4);

    $this->withToken($token1)->postJson('/api/notifications/read-all')->assertOk();

    expect(Notification::where('user_id', (string) $user2->_id)->whereNull('read_at')->count())->toBe(4);
});
