<?php

// Feature: admin-business-intelligence
// Property 11: Notification unread count matches database state
// Property 12: Mark-all-read leaves zero unread notifications

use App\Models\Notification;
use App\Models\User;

beforeEach(function () {
    Notification::truncate();
});

afterEach(function () {
    Notification::truncate();
});

// ── Helpers ──────────────────────────────────────────────────────────

function notifUser(): array
{
    $user  = User::factory()->employee()->create();
    $token = auth('api')->login($user);
    return [$user, $token];
}

function seedNotifications(User $user, int $unread, int $read = 0): void
{
    foreach (range(1, max(1, $unread)) as $i) {
        if ($i > $unread) break;
        Notification::create([
            'user_id' => (string) $user->_id,
            'type'    => 'broadcast',
            'message' => "Unread notification {$i}",
            'read_at' => null,
        ]);
    }
    foreach (range(1, max(1, $read)) as $i) {
        if ($i > $read) break;
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

// ── GET /api/notifications ────────────────────────────────────────────

test('authenticated user can list their notifications', function () {
    [$user, $token] = notifUser();
    seedNotifications($user, 3);

    $this->withToken($token)->getJson('/api/notifications')
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'current_page', 'per_page', 'total', 'total_pages']);

    $user->delete();
});

test('notifications are ordered newest first', function () {
    [$user, $token] = notifUser();
    seedNotifications($user, 3);

    $resp = $this->withToken($token)->getJson('/api/notifications')->assertStatus(200);
    $dates = collect($resp->json('data'))->pluck('created_at')->toArray();

    for ($i = 1; $i < count($dates); $i++) {
        expect($dates[$i])->toBeLessThanOrEqual($dates[$i - 1]);
    }

    $user->delete();
});

test('notifications are scoped to the authenticated user', function () {
    [$user1, $token1] = notifUser();
    [$user2, $token2] = notifUser();

    seedNotifications($user1, 2);
    seedNotifications($user2, 5);

    $resp = $this->withToken($token1)->getJson('/api/notifications')->assertStatus(200);
    expect($resp->json('total'))->toBe(2);

    $user1->delete();
    $user2->delete();
});

test('unauthenticated user cannot list notifications', function () {
    $this->getJson('/api/notifications')->assertStatus(401);
});

// ── GET /api/notifications/unread-count ──────────────────────────────

// Property 11: unread count matches database state
test('unread count matches DB count of notifications with null read_at', function () {
    [$user, $token] = notifUser();
    seedNotifications($user, unread: 4, read: 2);

    $dbCount   = Notification::where('user_id', (string) $user->_id)->whereNull('read_at')->count();
    $apiCount  = $this->withToken($token)->getJson('/api/notifications/unread-count')
        ->assertStatus(200)->json('unread_count');

    expect($apiCount)->toBe($dbCount)->toBe(4);

    $user->delete();
});

test('unread count is 0 when all notifications are read', function () {
    [$user, $token] = notifUser();
    seedNotifications($user, unread: 0, read: 3);

    $this->withToken($token)->getJson('/api/notifications/unread-count')
        ->assertStatus(200)
        ->assertJson(['unread_count' => 0]);

    $user->delete();
});

test('unread count is 0 when user has no notifications', function () {
    [$user, $token] = notifUser();

    $this->withToken($token)->getJson('/api/notifications/unread-count')
        ->assertStatus(200)
        ->assertJson(['unread_count' => 0]);

    $user->delete();
});

// ── POST /api/notifications/{id}/read ────────────────────────────────

test('user can mark a single notification as read', function () {
    [$user, $token] = notifUser();
    $notif = Notification::create([
        'user_id' => (string) $user->_id,
        'type'    => 'broadcast',
        'message' => 'Hello',
        'read_at' => null,
    ]);

    $resp = $this->withToken($token)
        ->postJson("/api/notifications/{$notif->_id}/read")
        ->assertStatus(200);

    expect($resp->json('read_at'))->not->toBeNull();
    expect(Notification::find($notif->_id)->read_at)->not->toBeNull();

    $user->delete();
});

test('marking an already-read notification is idempotent', function () {
    [$user, $token] = notifUser();
    $readAt = now()->subMinutes(5);
    $notif  = Notification::create([
        'user_id' => (string) $user->_id,
        'type'    => 'broadcast',
        'message' => 'Hello',
        'read_at' => $readAt,
    ]);

    $this->withToken($token)
        ->postJson("/api/notifications/{$notif->_id}/read")
        ->assertStatus(200);

    $user->delete();
});

test('user cannot mark another users notification as read', function () {
    [$user1, $token1] = notifUser();
    [$user2, $token2] = notifUser();

    $notif = Notification::create([
        'user_id' => (string) $user2->_id,
        'type'    => 'broadcast',
        'message' => 'For user2',
        'read_at' => null,
    ]);

    $this->withToken($token1)
        ->postJson("/api/notifications/{$notif->_id}/read")
        ->assertStatus(404);

    $user1->delete();
    $user2->delete();
});

// ── POST /api/notifications/read-all ─────────────────────────────────

// Property 12: Mark-all-read leaves zero unread notifications
test('mark-all-read sets unread count to zero', function () {
    [$user, $token] = notifUser();
    seedNotifications($user, unread: 5, read: 2);

    $this->withToken($token)->postJson('/api/notifications/read-all')->assertStatus(200);

    $remaining = Notification::where('user_id', (string) $user->_id)->whereNull('read_at')->count();
    expect($remaining)->toBe(0);

    $apiCount = $this->withToken($token)->getJson('/api/notifications/unread-count')
        ->json('unread_count');
    expect($apiCount)->toBe(0);

    $user->delete();
});

test('mark-all-read works when there are no unread notifications', function () {
    [$user, $token] = notifUser();
    seedNotifications($user, unread: 0, read: 3);

    $this->withToken($token)->postJson('/api/notifications/read-all')
        ->assertStatus(200)
        ->assertJson(['updated' => 0]);

    $user->delete();
});

test('mark-all-read only affects the authenticated users notifications', function () {
    [$user1, $token1] = notifUser();
    [$user2, $token2] = notifUser();

    seedNotifications($user1, 3);
    seedNotifications($user2, 4);

    $this->withToken($token1)->postJson('/api/notifications/read-all')->assertStatus(200);

    // user2's notifications untouched
    $user2Unread = Notification::where('user_id', (string) $user2->_id)->whereNull('read_at')->count();
    expect($user2Unread)->toBe(4);

    $user1->delete();
    $user2->delete();
});
